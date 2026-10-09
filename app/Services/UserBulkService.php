<?php

namespace App\Services;

use App\Exceptions\InsufficientCredits;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Actions on many users at once: enable, disable, add or remove a bouquet, extend the subscription, delete.
 * Only subscribers are touched (never admins, resellers or the person asking). A reseller only works with their own users
 * (the query comes from User::visibleTo) and with the bouquets they hold; extending spends their credits like the user form.
 */
class UserBulkService
{
    public const ACTIONS = ['enable', 'disable', 'add_bouquet', 'remove_bouquet', 'extend', 'delete'];

    protected $users;

    protected $credits;

    public function __construct(UserService $users, CreditService $credits)
    {
        $this->users = $users;
        $this->credits = $credits;
    }

    /**
     * @param  Builder<User>  $query  already limited to what $actor may see
     * @param  array{months?: int, bouquet_id?: int}  $options
     * @return array{changed: int, spent: int}
     *
     * @throws InsufficientCredits when a reseller can't pay for an extension (nothing is changed)
     */
    public function apply(User $actor, string $action, Builder $query, array $options = []): array
    {
        $subscribers = (clone $query)->where('role', 'user')->whereKeyNot($actor->id);
        $ids = DB::query()->fromSub((clone $subscribers)->select('users.id')->toBase(), 'matched')->select('id');

        return match ($action) {
            'enable' => ['changed' => User::whereIn('id', $ids)->where('status', '!=', 'active')->update(['status' => 'active']), 'spent' => 0],
            'disable' => ['changed' => User::whereIn('id', $ids)->where('status', '!=', 'disabled')->update(['status' => 'disabled']), 'spent' => 0],
            'add_bouquet' => ['changed' => $this->addBouquet($subscribers, (int) $options['bouquet_id']), 'spent' => 0],
            'remove_bouquet' => ['changed' => DB::table('user_bouquet')->where('bouquet_id', (int) $options['bouquet_id'])->whereIn('user_id', $ids)->delete(), 'spent' => 0],
            'extend' => $this->extend($actor, $subscribers, (int) $options['months']),
            'delete' => ['changed' => User::whereIn('id', $ids)->delete(), 'spent' => 0],
            default => throw new \InvalidArgumentException("Unknown bulk action {$action}"),
        };
    }

    /** Give every matched user the bouquet they don't have yet. */
    private function addBouquet(Builder $subscribers, int $bouquetId): int
    {
        return DB::table('user_bouquet')->insertUsing(
            ['user_id', 'bouquet_id'],
            (clone $subscribers)
                ->selectRaw('users.id, ?', [$bouquetId])
                ->whereNotIn('users.id', DB::table('user_bouquet')->select('user_id')->where('bouquet_id', $bouquetId))
                ->toBase()
        );
    }

    /**
     * Move every matched user's end date later by $months (from their current end date, or from now when it ended). A reseller with
     * credits pays 1 per month per user, all or nothing. Users who never expire are left as they are.
     *
     * @return array{changed: int, spent: int}
     */
    private function extend(User $actor, Builder $subscribers, int $months): array
    {
        return DB::transaction(function () use ($actor, $subscribers, $months) {
            $locked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $pays = $actor->isReseller() && $locked->usesCredits();
            $plan = [];
            $total = 0;

            /** @var Collection<int, User> $matched */
            $matched = (clone $subscribers)->whereNotNull('expiration_date')->get();
            foreach ($matched as $user) {
                $until = $this->users->renewedUntil($user, $months);
                $cost = $pays ? Credits::months($user->expiration_date, $until) : 0;
                $plan[] = [$user, $until, $cost];
                $total += $cost;
            }

            // All or nothing: the credits must cover everyone before anything is changed
            if ($pays && $total > $locked->credits) {
                throw new InsufficientCredits($total, $locked->credits);
            }

            if ($total > 0) {
                foreach ($plan as [$user, , $cost]) {
                    $this->credits->charge($actor, $cost, CreditTransaction::EXTENDED, $user);
                }
            }

            foreach ($plan as [$user, $until]) {
                $user->forceFill(['expiration_date' => $until, 'is_trial' => false, 'status' => 'active'])->save();
            }

            return ['changed' => count($plan), 'spent' => $total];
        });
    }
}
