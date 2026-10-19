<?php

namespace App\Services;

use App\Exceptions\InsufficientCredits;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A reseller's credits: the balance on `users.credits` and the history in `credit_transactions`.
 * 1 credit = 1 month of subscription for one user (App\Support\Credits). A null balance means the
 * reseller isn't limited by credits. Every method that changes a balance runs in a transaction with
 * the reseller's row locked, so two requests can't both spend the same credits.
 */
class CreditService
{
    /**
     * Spend credits for a reseller's user. Throws InsufficientCredits (nothing is changed).
     * Does nothing for a reseller without a credit balance.
     */
    public function charge(User $reseller, int $amount, string $reason, ?User $customer = null): void
    {
        DB::transaction(function () use ($reseller, $amount, $reason, $customer) {
            $locked = $this->lock($reseller);

            if (!$locked->usesCredits() || $amount <= 0) {
                return;
            }

            if ($amount > $locked->credits) {
                throw new InsufficientCredits($amount, $locked->credits);
            }

            $locked->forceFill(['credits' => $locked->credits - $amount])->save();
            $this->record($locked, $reseller, -$amount, $reason, $customer);
        });
    }

    /**
     * An admin gives (positive) or takes back (negative) credits. The balance can't go below 0. A reseller
     * without a balance starts from 0, so giving credits is also how the credit limit is switched on.
     */
    public function adjust(User $admin, User $reseller, int $amount, ?string $note = null): CreditTransaction
    {
        return DB::transaction(function () use ($admin, $reseller, $amount, $note) {
            $locked = $this->lock($reseller);
            $balance = ($locked->credits ?? 0) + $amount;

            if ($balance < 0) {
                throw new InsufficientCredits(-$amount, $locked->credits ?? 0);
            }

            $locked->forceFill(['credits' => $balance])->save();

            // Credits given are charged (taken back: refunded) at the reseller's price per credit now (SaleService)
            return $this->record($locked, $admin, $amount, $amount >= 0 ? CreditTransaction::GIVEN : CreditTransaction::TAKEN, null, $note, app(SaleService::class)->creditPrice($locked));
        });
    }

    /** Take the credit limit off a reseller again. */
    public function makeUnlimited(User $admin, User $reseller, ?string $note = null): CreditTransaction
    {
        return DB::transaction(function () use ($admin, $reseller, $note) {
            $locked = $this->lock($reseller);
            $locked->forceFill(['credits' => null])->save();

            return $this->record($locked, $admin, 0, CreditTransaction::UNLIMITED, null, $note);
        });
    }

    private function lock(User $reseller): User
    {
        return User::whereKey($reseller->id)->lockForUpdate()->firstOrFail();
    }

    private function record(User $locked, User $actor, int $amount, string $reason, ?User $customer = null, ?string $note = null, ?float $price = null): CreditTransaction
    {
        return CreditTransaction::create([
            'price' => $price,
            'user_id' => $locked->id,
            'actor_id' => $actor->id,
            'customer_id' => $customer?->id,
            'amount' => $amount,
            'balance_after' => $locked->credits,
            'reason' => $reason,
            'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
        ]);
    }
}
