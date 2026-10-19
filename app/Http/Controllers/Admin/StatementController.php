<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ResellerStatementService;
use App\Services\UserCsvService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The monthly statement of a reseller: the reseller sees their own, an admin picks the reseller.
 */
class StatementController extends Controller
{
    protected $statements;

    public function __construct(ResellerStatementService $statements)
    {
        $this->statements = $statements;
    }

    public function index(Request $request)
    {
        [$resellers, $reseller, $month] = $this->choose($request);

        return view('admin.statements.index', [
            'resellers' => $resellers,
            'reseller' => $reseller,
            'statement' => $reseller ? $this->statements->build($reseller, $month) : null,
            'months' => $reseller ? $this->statements->months($reseller) : [],
        ]);
    }

    public function csv(Request $request)
    {
        [, $reseller, $month] = $this->choose($request);
        abort_if($reseller === null, 404);
        $s = $this->statements->build($reseller, $month);

        return response()->streamDownload(function () use ($s) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $safe = fn ($v) => UserCsvService::safe($v);
            fputcsv($out, [__('Statement'), $s['reseller']['name'], $s['month']]);
            foreach ([
                __('Opening balance') => $s['credits']['opening'], __('Credits given') => $s['credits']['given'], __('Credits taken back') => $s['credits']['taken_back'],
                __('Used for new subscriptions') => $s['credits']['used_new'], __('Used for renewals') => $s['credits']['used_renewals'], __('Closing balance') => $s['credits']['closing'],
                __('New subscriptions') => $s['sales']['new_subscriptions'], __('Renewals') => $s['sales']['renewals'],
                __('Users added') => $s['users']['added'], __('Subscriptions ended') => $s['users']['ended'], __('Active users now') => $s['users']['active_now'],
            ] as $label => $value) {
                fputcsv($out, [$label, $value ?? '']);
            }
            fputcsv($out, []);
            fputcsv($out, [__('Date'), __('What'), __('User'), __('Credits'), __('Balance'), __('Note')]);
            foreach ($s['lines'] as $line) {
                fputcsv($out, [$line['date'], $line['type'], $safe($line['customer']), $line['credits'], $line['balance'] ?? '', $safe($line['note'])]);
            }
            fclose($out);
        }, 'statement-'.$s['reseller']['name'].'-'.$s['month'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Collection, 1: ?User, 2: Carbon} */
    private function choose(Request $request): array
    {
        $actor = $request->user();
        $resellers = $actor->isAdmin() ? User::where('role', 'reseller')->orderBy('username')->get(['id', 'username']) : collect([$actor]);
        $reseller = $actor->isAdmin() ? ($resellers->firstWhere('id', (int) $request->input('reseller')) ?? $resellers->first()) : $actor;
        $reseller = $reseller ? User::find($reseller->id) : null;

        $input = $request->input('month');
        $month = is_string($input) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $input) ? Carbon::createFromFormat('Y-m-d', $input.'-01')->startOfMonth() : now()->startOfMonth();

        return [$resellers, $reseller, $month->gt(now()) ? now()->startOfMonth() : $month];
    }
}
