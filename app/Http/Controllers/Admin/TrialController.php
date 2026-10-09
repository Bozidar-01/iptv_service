<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ResellerLimitReached;
use App\Http\Controllers\Controller;
use App\Models\Bouquet;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "New trial": a short test account with a random login, one device and no credits spent.
 * A reseller can make iptv.trials_per_day of them per 24 hours (and counts against their user limit); admins are not limited.
 */
class TrialController extends Controller
{
    /** Hours a trial can last. */
    public const HOURS = [24, 72, 168];

    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function create(Request $request)
    {
        return view('admin.users.trial', ['bouquets' => $this->bouquets($request->user())->withCount('channels')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();

        $data = $request->validate([
            'hours' => ['required', Rule::in(self::HOURS)],
            'bouquets' => ['required', 'array', 'min:1'],
            'bouquets.*' => [Rule::in($this->bouquets($actor)->pluck('bouquets.id')->all())],
        ], ['bouquets.required' => __('Choose at least one bouquet.')]);

        try {
            // Checked under a lock on the reseller's row, so parallel clicks can't get past the limits
            $user = DB::transaction(function () use ($actor, $data) {
                if ($actor->isReseller()) {
                    $reseller = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                    if (!$reseller->canAddCustomer()) {
                        throw new ResellerLimitReached;
                    }
                    if ($this->userService->recentTrials($reseller) >= config('iptv.trials_per_day')) {
                        throw new \OverflowException;
                    }
                }

                return $this->userService->createTrial($actor->isReseller() ? $actor : null, (int) $data['hours'], $data['bouquets']);
            });
        } catch (ResellerLimitReached) {
            return back()->withInput()->with('error', __('You have reached your limit of :count users.', ['count' => $actor->max_customers]));
        } catch (\OverflowException) {
            return back()->withInput()->with('error', __('You can make :count trials per day. Try again tomorrow.', ['count' => config('iptv.trials_per_day')]));
        }

        return redirect()->route('admin.users.show', $user)->with('success', __('Trial created. Copy the message below and send it to the user.'));
    }

    /** What the person may put on a trial: all bouquets for an admin, the ones they hold for a reseller. */
    private function bouquets(User $actor)
    {
        return $actor->isAdmin() ? Bouquet::query() : $actor->bouquets();
    }
}
