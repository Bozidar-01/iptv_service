<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A reseller's month in numbers, for them and for the admin: credits given and used, what they sold (new and renewed
 * subscriptions) and what happened to their users. Credits are the sales (1 credit = 1 month), read from the credit history.
 */
class ResellerStatementService
{
    /**
     * @return array{reseller: array{id: int, name: string, unlimited: bool}, month: string, from: string, to: string, credits: array<string, int|null>, sales: array<string, int>, users: array<string, int>, lines: list<array<string, mixed>>}
     */
    public function build(User $reseller, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $unlimited = !$reseller->usesCredits() && !CreditTransaction::where('user_id', $reseller->id)->exists();

        $transactions = CreditTransaction::where('user_id', $reseller->id)->whereBetween('created_at', [$from, $to]);
        $sum = fn (array $reasons, bool $positive = false) => (int) (clone $transactions)->whereIn('reason', $reasons)->when($positive, fn ($q) => $q->where('amount', '>', 0))->sum('amount');
        $count = fn (string $reason) => (clone $transactions)->where('reason', $reason)->count();

        $before = CreditTransaction::where('user_id', $reseller->id)->where('created_at', '<', $from)->latest('id')->first();
        $last = (clone $transactions)->latest('id')->first();
        // Nothing before this month means the reseller started at 0 (every credit change is in the history)
        $opening = $before ? $before->balance_after : ($unlimited ? null : 0);
        $closing = $last ? $last->balance_after : ($before ? $before->balance_after : null);
        if ($closing === null && $month->isSameMonth(now())) {
            $closing = $reseller->credits;
        }

        $owned = User::where('owner_id', $reseller->id)->where('role', 'user');

        return [
            'reseller' => ['id' => $reseller->id, 'name' => $reseller->username, 'unlimited' => $unlimited],
            'month' => $from->format('Y-m'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'credits' => [
                'opening' => $opening,
                'given' => $sum([CreditTransaction::GIVEN], true),
                'taken_back' => -$sum([CreditTransaction::TAKEN]),
                'used_new' => -$sum([CreditTransaction::NEW_SUBSCRIPTION]),
                'used_renewals' => -$sum([CreditTransaction::EXTENDED]),
                'closing' => $closing,
            ],
            'sales' => [
                'new_subscriptions' => $count(CreditTransaction::NEW_SUBSCRIPTION),
                'renewals' => $count(CreditTransaction::EXTENDED),
            ],
            'users' => [
                'added' => (clone $owned)->whereBetween('created_at', [$from, $to])->count(),
                'ended' => (clone $owned)->whereBetween('expiration_date', [$from, $to])->count(),
                'active_now' => (clone $owned)->where('status', 'active')->where(fn ($q) => $q->whereNull('expiration_date')->orWhere('expiration_date', '>', now()))->count(),
                'total' => (clone $owned)->count(),
            ],
            'lines' => CreditTransaction::with('customer:id,username')->where('user_id', $reseller->id)->whereBetween('created_at', [$from, $to])->orderBy('id')->limit(1000)->get()
                ->map(fn (CreditTransaction $t) => ['date' => $t->created_at->toDateTimeString(), 'type' => $t->label(), 'customer' => $t->customer?->username, 'credits' => $t->amount, 'balance' => $t->balance_after, 'note' => $t->note])->all(),
        ];
    }

    /** Months that have credit history for this reseller (newest first), or just this month. */
    public function months(User $reseller): array
    {
        $first = CreditTransaction::where('user_id', $reseller->id)->min('created_at');
        $months = [];
        $cursor = now()->startOfMonth();
        $stop = $first ? Carbon::parse($first)->startOfMonth() : $cursor->copy();
        for ($i = 0; $i < 36 && $cursor->gte($stop); $i++, $cursor->subMonth()) {
            $months[] = $cursor->format('Y-m');
        }

        return $months;
    }
}
