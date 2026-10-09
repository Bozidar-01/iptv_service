<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The business view for the admin: how many subscriptions each reseller sold, who is about to run out of credits and whose
 * customers are leaving. Credits are the sales (1 credit = 1 month), read from the credit history; users come from the users table.
 * Grouped queries only, nothing is loaded row by row.
 */
class SalesReportService
{
    public const PERIODS = [30, 90, 365];

    /** Months shown in the chart. */
    public const MONTHS = 12;

    /** What counts as "about to run out": credits for fewer days than this at the recent pace. */
    public const LOW_DAYS = 14;

    /**
     * @return array{days: int, from: string, totals: array<string, int>, months: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function report(int $days): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : self::PERIODS[0];
        $from = now()->startOfDay()->subDays($days - 1);
        $recent = now()->subDays(30);
        $spendReasons = [CreditTransaction::NEW_SUBSCRIPTION, CreditTransaction::EXTENDED];

        // Months sold (credits spent) per calendar month, oldest first, empty months included
        $firstMonth = now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $months = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[$firstMonth->copy()->addMonths($i)->format('Y-m')] = 0;
        }
        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        foreach (CreditTransaction::whereIn('reason', $spendReasons)->where('created_at', '>=', $firstMonth)
            ->select(DB::raw("$monthExpr as month"), DB::raw('sum(-amount) as credits'))->groupBy('month')->toBase()->get() as $row) {
            if (isset($months[$row->month])) {
                $months[$row->month] = (int) $row->credits;
            }
        }

        // Credits per reseller: spent and given in the period, spent in the last 30 days (the pace)
        $credits = CreditTransaction::query()->select(
            'user_id',
            DB::raw('sum(case when reason in (?, ?) and created_at >= ? then -amount else 0 end) as used'),
            DB::raw('sum(case when reason = ? and amount > 0 and created_at >= ? then amount else 0 end) as given'),
            DB::raw('sum(case when reason in (?, ?) and created_at >= ? then -amount else 0 end) as recent'),
        )->addBinding([...$spendReasons, $from, CreditTransaction::GIVEN, $from, ...$spendReasons, $recent], 'select')
            ->groupBy('user_id')->toBase()->get()->keyBy('user_id');

        // Users per owner (null = the admins' own users)
        $now = now();
        $users = User::where('role', 'user')->select(
            'owner_id',
            DB::raw('count(*) as total'),
            DB::raw("sum(case when status = 'active' and (expiration_date is null or expiration_date > ?) then 1 else 0 end) as active"),
            DB::raw('sum(case when created_at >= ? then 1 else 0 end) as new'),
            DB::raw('sum(case when expiration_date > ? and expiration_date <= ? then 1 else 0 end) as expiring'),
            DB::raw('sum(case when expiration_date > ? and expiration_date <= ? then 1 else 0 end) as expired'),
        )->addBinding([$now, $from, $now, $now->copy()->addDays(30), $recent, $now], 'select')
            ->groupBy('owner_id')->toBase()->get()->keyBy(fn ($row) => $row->owner_id ?? 0);

        $rows = [];
        foreach (User::where('role', 'reseller')->orderBy('username')->get(['id', 'username', 'credits', 'status']) as $reseller) {
            $c = $credits->get($reseller->id);
            $u = $users->get($reseller->id);
            $perDay = $c ? ((int) $c->recent) / 30 : 0;
            $rows[] = [
                'id' => $reseller->id,
                'name' => $reseller->username,
                'credits' => $reseller->credits,
                'used' => (int) ($c->used ?? 0),
                'given' => (int) ($c->given ?? 0),
                'days_left' => $reseller->credits !== null && $perDay > 0 ? (int) floor($reseller->credits / $perDay) : null,
                'total' => (int) ($u->total ?? 0),
                'active' => (int) ($u->active ?? 0),
                'new' => (int) ($u->new ?? 0),
                'expiring' => (int) ($u->expiring ?? 0),
                'expired' => (int) ($u->expired ?? 0),
            ];
        }
        if ($own = $users->get(0)) {
            $rows[] = ['id' => null, 'name' => null, 'credits' => null, 'used' => 0, 'given' => 0, 'days_left' => null, 'total' => (int) $own->total, 'active' => (int) $own->active, 'new' => (int) $own->new, 'expiring' => (int) $own->expiring, 'expired' => (int) $own->expired];
        }

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'totals' => [
                'used' => array_sum(array_column($rows, 'used')),
                'given' => array_sum(array_column($rows, 'given')),
                'new' => array_sum(array_column($rows, 'new')),
                'active' => array_sum(array_column($rows, 'active')),
                'expiring' => array_sum(array_column($rows, 'expiring')),
                'expired' => array_sum(array_column($rows, 'expired')),
            ],
            'months' => $months,
            'rows' => $rows,
        ];
    }
}
