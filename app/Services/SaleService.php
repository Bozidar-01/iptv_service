<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CreditTransaction;
use App\Models\ResellerPayment;
use App\Models\Sale;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money: prices, the months sold to users, and what resellers owe for credits.
 *
 *  - Prices: the admins' price per month for their own users and the default price per credit for resellers (setting `money`,
 *    with the currency); each reseller's own price per month for their users (`users.price_per_month`) and, set by an admin, the
 *    price that reseller pays per credit (`users.credit_price`, else the default).
 *  - Sales: every time months are added to a user (new subscription, renewal, a later end date, bulk extend, CSV import) a
 *    `sales` row records the months (counted like credits, App\Support\Credits) and their price for the user's seller: their
 *    reseller, or the admins. Trials are not sales. A sale is paid once the seller marks it so.
 *  - Resellers: credits given are charged at the reseller's price per credit at that moment (kept on the credit history row);
 *    what they paid is recorded by an admin (`reseller_payments`). The difference is what they owe.
 */
class SaleService
{
    public const SETTING = 'money';

    /** @return array{currency: string, price_per_month: ?float, credit_price: ?float} */
    public function settings(): array
    {
        $saved = (array) AppSetting::get(self::SETTING, []);

        return [
            'currency' => is_string($saved['currency'] ?? null) && $saved['currency'] !== '' ? $saved['currency'] : 'EUR',
            'price_per_month' => isset($saved['price_per_month']) ? (float) $saved['price_per_month'] : null,
            'credit_price' => isset($saved['credit_price']) ? (float) $saved['credit_price'] : null,
        ];
    }

    /** @param  array{currency?: string, price_per_month?: ?float, credit_price?: ?float}  $values */
    public function saveSettings(array $values): void
    {
        AppSetting::put(self::SETTING, array_merge($this->settings(), $values));
    }

    /** What the seller asks per month: a reseller's own price, or the admins' (null seller). Null = no price set. */
    public function pricePerMonth(?User $seller): ?float
    {
        return $seller ? ($seller->price_per_month !== null ? (float) $seller->price_per_month : null) : $this->settings()['price_per_month'];
    }

    /** What a reseller pays per credit: their own price, else the default. */
    public function creditPrice(User $reseller): ?float
    {
        return $reseller->credit_price !== null ? (float) $reseller->credit_price : $this->settings()['credit_price'];
    }

    /**
     * Record the months added to a user, if any: from the end date before to the one after (null before = a new subscription).
     */
    public function record(User $customer, ?Carbon $before, ?Carbon $after, string $kind, ?User $actor = null): ?Sale
    {
        if ($customer->role !== 'user' || $customer->is_trial || $after === null) {
            return null;
        }

        $months = Credits::months($before, $after);
        if ($months <= 0) {
            return null;
        }

        $seller = $customer->owner_id ? User::find($customer->owner_id) : null;
        $price = $this->pricePerMonth($seller);

        return Sale::create([
            'owner_id' => $seller?->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->username,
            'kind' => $kind,
            'months' => $months,
            'amount' => $price !== null ? round($months * $price, 2) : null,
            'created_by' => $actor?->id,
        ]);
    }

    public function setPaid(Sale $sale, bool $paid): void
    {
        $sale->update(['paid_at' => $paid ? ($sale->paid_at ?? now()) : null]);
    }

    /** The sales an admin (all) or a reseller (their own) can see and mark. */
    public function visibleTo(User $actor): Builder
    {
        return Sale::query()->when(!$actor->isAdmin(), fn ($q) => $q->where('owner_id', $actor->id));
    }

    public function canManage(User $actor, Sale $sale): bool
    {
        return $actor->isAdmin() || $sale->owner_id === $actor->id;
    }

    /**
     * Unpaid now, and sold / received this month, for the sales in the query.
     *
     * @return array{unpaid: int, unpaid_amount: float, sold_month: float, received_month: float}
     */
    public function totals(Builder $sales): array
    {
        $month = now()->startOfMonth();
        $row = (clone $sales)->toBase()->selectRaw(
            'SUM(CASE WHEN paid_at IS NULL THEN 1 ELSE 0 END) as unpaid, SUM(CASE WHEN paid_at IS NULL THEN amount ELSE 0 END) as unpaid_amount,
             SUM(CASE WHEN created_at >= ? THEN amount ELSE 0 END) as sold_month, SUM(CASE WHEN paid_at >= ? THEN amount ELSE 0 END) as received_month',
            [$month, $month]
        )->first();

        return [
            'unpaid' => (int) ($row->unpaid ?? 0),
            'unpaid_amount' => (float) ($row->unpaid_amount ?? 0),
            'sold_month' => (float) ($row->sold_month ?? 0),
            'received_month' => (float) ($row->received_month ?? 0),
        ];
    }

    /**
     * What each reseller was charged for credits, paid, and owes. One query each for charges and payments.
     *
     * @param  array<int>|null  $ids  resellers, or all
     * @return array<int, array{charged: float, paid: float, owes: float, last_paid: ?string}>
     */
    public function balances(?array $ids = null): array
    {
        $charged = CreditTransaction::query()->whereIn('reason', [CreditTransaction::GIVEN, CreditTransaction::TAKEN])->whereNotNull('price')
            ->when($ids !== null, fn ($q) => $q->whereIn('user_id', $ids))
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount * price) as charged')->pluck('charged', 'user_id');
        $paid = ResellerPayment::query()->when($ids !== null, fn ($q) => $q->whereIn('reseller_id', $ids))
            ->groupBy('reseller_id')->selectRaw('reseller_id, SUM(amount) as paid, MAX(paid_on) as last_paid')->toBase()->get()->keyBy('reseller_id');

        $result = [];
        foreach ($ids ?? $charged->keys()->merge($paid->keys())->unique()->all() as $id) {
            $c = round((float) ($charged[$id] ?? 0), 2);
            $p = round((float) ($paid[$id]->paid ?? 0), 2);
            $result[(int) $id] = ['charged' => $c, 'paid' => $p, 'owes' => round($c - $p, 2), 'last_paid' => isset($paid[$id]) ? Carbon::parse($paid[$id]->last_paid)->toDateString() : null];
        }

        return $result;
    }

    public function recordPayment(User $admin, User $reseller, float $amount, ?string $note, Carbon $on): ResellerPayment
    {
        return ResellerPayment::create([
            'reseller_id' => $reseller->id,
            'amount' => round($amount, 2),
            'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
            'paid_on' => $on->toDateString(),
            'recorded_by' => $admin->id,
        ]);
    }

    /**
     * Money in one month, for a reseller's statement: what they sold and received from their users, and what their credits cost
     * and what they paid for them.
     *
     * @return array{sold: float, received: float, unpaid: float, credits_cost: float, paid_for_credits: float, owes: float}
     */
    public function month(User $reseller, Carbon $from, Carbon $to): array
    {
        $sales = Sale::where('owner_id', $reseller->id);
        $row = (clone $sales)->toBase()->selectRaw(
            'SUM(CASE WHEN created_at BETWEEN ? AND ? THEN amount ELSE 0 END) as sold, SUM(CASE WHEN paid_at BETWEEN ? AND ? THEN amount ELSE 0 END) as received',
            [$from, $to, $from, $to]
        )->first();
        $cost = CreditTransaction::where('user_id', $reseller->id)->whereIn('reason', [CreditTransaction::GIVEN, CreditTransaction::TAKEN])
            ->whereNotNull('price')->whereBetween('created_at', [$from, $to])->sum(DB::raw('amount * price'));
        $paid = ResellerPayment::where('reseller_id', $reseller->id)->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount');

        return [
            'sold' => round((float) ($row->sold ?? 0), 2),
            'received' => round((float) ($row->received ?? 0), 2),
            'unpaid' => round((float) (clone $sales)->whereNull('paid_at')->sum('amount'), 2),
            'credits_cost' => round((float) $cost, 2),
            'paid_for_credits' => round((float) $paid, 2),
            'owes' => $this->balances([$reseller->id])[$reseller->id]['owes'],
        ];
    }
}
