<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CleanupRule;
use App\Models\Source;
use App\Services\AuditService;
use App\Services\CleanupRuleService;
use App\Services\CountryOrganizeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cleanup rules page (admins): tidy channel names and categories on every import, and on demand.
 */
class CleanupRuleController extends Controller
{
    protected $cleanup;

    protected $audit;

    protected $country;

    public function __construct(CleanupRuleService $cleanup, AuditService $audit, CountryOrganizeService $country)
    {
        $this->country = $country;
        $this->cleanup = $cleanup;
        $this->audit = $audit;
    }

    public function index()
    {
        return view('admin.cleanup.index', [
            'rules' => CleanupRule::with('source:id,name')->where('kind', '!=', CountryOrganizeService::KIND)->orderBy('sort_order')->orderBy('id')->get(),
            'country' => $this->country->settings(),
            'sources' => Source::orderBy('name')->get(['id', 'name']),
            'packs' => array_keys(CleanupRuleService::PACKS),
        ]);
    }

    /** Save a rule, or with `preview` show what it would do first (nothing is saved). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(CleanupRule::KINDS)],
            'match' => ['required', Rule::in(CleanupRule::MATCHES)],
            'pattern' => ['required', 'string', 'max:255'],
            'replacement' => [Rule::requiredIf(fn () => $request->input('kind') !== CleanupRule::CHANNEL_NAME || $request->input('match') === 'equals'), 'nullable', 'string', 'max:255'],
            'when_category' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ], ['replacement.required' => __('Enter what it should become.')]);

        if (!CleanupRule::patternIsValid($data['match'], $data['pattern'])) {
            return back()->withInput()->withErrors(['pattern' => __('This is not a valid pattern.')]);
        }

        // Only a rule that picks a category can depend on the current one
        if ($data['kind'] !== CleanupRule::CHANNEL_CATEGORY || ($data['when_category'] ?? '') === '') {
            $data['when_category'] = null;
        }

        $rule = new CleanupRule($data + ['enabled' => true]);

        if ($request->boolean('preview')) {
            return back()->withInput()->with('preview', $this->cleanup->preview(collect([$rule])));
        }

        $rule->sort_order = (int) CleanupRule::max('sort_order') + 1;
        $rule->save();

        return back()->with('success', __('Rule added. It applies to the next import; press Apply now to change the channels you have.'));
    }

    public function toggle(CleanupRule $rule)
    {
        $rule->update(['enabled' => !$rule->enabled]);

        return back();
    }

    public function destroy(CleanupRule $rule)
    {
        $rule->delete();

        return back()->with('success', __('Rule deleted.'));
    }

    public function pack(string $pack)
    {
        abort_unless(isset(CleanupRuleService::PACKS[$pack]), 404);

        $added = $this->cleanup->addPack($pack);

        return back()->with($added ? 'success' : 'error', $added
            ? trans_choice(':count rule added.|:count rules added.', $added, ['count' => $added])
            : __('These rules are already there.'));
    }

    /** Apply the saved rules to the channels already stored. */
    public function apply()
    {
        $result = $this->cleanup->applyToExisting();
        // Then the country categories, when they are switched on
        if ($this->country->settings()['enabled']) {
            $result['categories'] += $this->country->apply()['changed'];
        }

        if ($result['names'] + $result['categories'] > 0) {
            $this->audit->record(AuditLog::CLEANUP_APPLIED, null, ['names' => $result['names'], 'categories' => $result['categories']]);
        }

        return back()->with('success', __(':names names and :categories categories changed.', ['names' => number_format($result['names']), 'categories' => number_format($result['categories'])]));
    }

    /**
     * Country categories: `preview` shows what would happen with the form's settings, `save` stores them, `apply` stores them and moves
     * the channels now. Switching off stops it from running on imports (channels keep their categories).
     */
    public function country(Request $request)
    {
        $data = $request->validate([
            'split' => ['required', 'integer', 'min:0', 'max:100000'],
            'min' => ['required', 'integer', 'min:1', 'max:100000'],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['integer', 'exists:sources,id'],
            'enabled' => ['nullable', 'boolean'],
            'do' => ['required', 'in:preview,save,apply'],
        ]);
        $settings = ['split' => (int) $data['split'], 'min' => (int) $data['min'], 'exclude' => array_map('intval', $data['exclude'] ?? [])];

        if ($data['do'] === 'preview') {
            $plan = $this->country->plan(null, $settings);

            return back()->withInput()->with('country_preview', ['counts' => $plan['counts'], 'international' => $plan['international'], 'total' => $plan['total']]);
        }

        $enabled = $request->boolean('enabled') || $data['do'] === 'apply';
        $this->country->save($settings, $enabled);

        if ($data['do'] === 'apply') {
            $result = $this->country->apply();
            $this->audit->record(AuditLog::CLEANUP_APPLIED, null, ['names' => 0, 'categories' => $result['changed']]);

            return back()->with('success', __(':count channels moved into country categories.', ['count' => number_format($result['changed'])]));
        }

        return back()->with('success', $enabled ? __('Saved. Country categories are on: every import follows them.') : __('Saved. Country categories are off.'));
    }
}
