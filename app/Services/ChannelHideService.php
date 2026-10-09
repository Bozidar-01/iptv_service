<?php

namespace App\Services;

use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Hiding channels without touching bouquets (a bouquet is shared by many users and resellers).
 *
 * Two lists: channels hidden for one user (`user_hidden_channels`), and channels hidden for all users of
 * one owner (`owner_hidden_channels`: a reseller's customers, or with owner null the admins' own users).
 * Channel::availableTo() applies both, so playlists, the Xtream API, the guide and playback all follow.
 * Everything is set-based (insertUsing / one DELETE), so hiding a whole bouquet is a few queries.
 */
class ChannelHideService
{
    public const ACTIONS = ['hide', 'show'];

    /** Whose list "hide for all my users" edits: a reseller's own, or null (the admins' users) for an admin. */
    public function ownerKey(User $actor): ?int
    {
        return $actor->isReseller() ? $actor->id : null;
    }

    /**
     * The bouquets $actor can offer, or (with $user) the user's bouquets as far as $actor may see them:
     * a reseller only works with the bouquets they hold themselves.
     */
    public function bouquetsFor(User $actor, ?User $user = null): Builder|Relation
    {
        $bouquets = $user ? $user->bouquets() : Bouquet::query();

        if ($actor->isReseller()) {
            $bouquets->whereIn('bouquets.id', $actor->bouquets()->select('bouquets.id'));
        }

        return $bouquets;
    }

    /** Enabled channels in those bouquets. */
    public function channelsIn(Builder|Relation $bouquets): Builder
    {
        return Channel::query()->where('channels.is_active', true)
            ->whereHas('bouquets', fn ($q) => $q->whereIn('bouquets.id', (clone $bouquets)->select('bouquets.id')));
    }

    /**
     * Filters: search, category (id or "none"), bouquet (id), state ("hidden" | "visible").
     * $ownerId / $user say which hide lists count for the state.
     *
     * @param  array<string, mixed>  $filters
     */
    public function filter(Builder $channels, array $filters, ?int $ownerId, ?User $user = null): Builder
    {
        return $channels
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('channels.name', 'like', '%'.$search.'%'))
            ->when($filters['category'] ?? null, fn ($q, $category) => $category === 'none'
                ? $q->whereNull('channels.category_id')
                : $q->where('channels.category_id', $category))
            ->when($filters['bouquet'] ?? null, fn ($q, $bouquet) => $q->whereHas('bouquets', fn ($b) => $b->where('bouquets.id', $bouquet)))
            ->when(in_array($filters['state'] ?? null, ['hidden', 'visible'], true), function ($q) use ($filters, $ownerId, $user) {
                $hidden = fn ($q) => $q->whereIn('channels.id', $this->ownerRows($ownerId)->select('channel_id'))
                    ->when($user, fn ($q) => $q->orWhereIn('channels.id', $this->userRows($user)->select('channel_id')));

                return $filters['state'] === 'hidden'
                    ? $q->where($hidden)
                    : $q->whereNot($hidden);
            });
    }

    /** Categories that have channels in the query (for the filter menu). */
    public function categoriesOf(Builder $channels)
    {
        return Category::whereIn('id', (clone $channels)->whereNotNull('channels.category_id')->select('channels.category_id'))->orderBy('name')->get(['id', 'name']);
    }

    /**
     * The categories of these channels with how many channels each has and how many are hidden: for hiding a whole
     * category in one click. The "no category" group has id null.
     *
     * @return list<array{id: ?int, name: ?string, total: int, hidden: int}>
     */
    public function categoryStats(Builder $channels, ?int $ownerId, ?User $user = null): array
    {
        $count = fn (Builder $q) => $q->toBase()->reorder()->select('channels.category_id', DB::raw('count(*) as n'))->groupBy('channels.category_id')->pluck('n', 'category_id');

        $totals = $count(clone $channels);
        $hidden = $count($this->filter(clone $channels, ['state' => 'hidden'], $ownerId, $user));
        $names = Category::whereIn('id', $totals->keys()->filter())->pluck('name', 'id');

        return $totals->map(fn ($n, $id) => [
            'id' => $id === '' ? null : ($id === null ? null : (int) $id),
            'name' => $id ? ($names[$id] ?? null) : null,
            'total' => (int) $n,
            'hidden' => (int) ($hidden[$id] ?? 0),
        ])->sortBy(fn ($row) => mb_strtolower($row['name'] ?? '~'))->values()->all();
    }

    /** @return array{owner: list<int>, user: list<int>} which of these channels are hidden, and by which list */
    public function hiddenAmong(array $channelIds, ?int $ownerId, ?User $user = null): array
    {
        return [
            'owner' => $this->ownerRows($ownerId)->whereIn('channel_id', $channelIds)->pluck('channel_id')->all(),
            'user' => $user ? $this->userRows($user)->whereIn('channel_id', $channelIds)->pluck('channel_id')->all() : [],
        ];
    }

    /** @return int channels newly hidden (those already hidden are skipped) */
    public function hideForOwner(?int $ownerId, Builder $channels): int
    {
        return DB::table('owner_hidden_channels')->insertUsing(
            ['owner_id', 'channel_id'],
            (clone $channels)
                ->selectRaw('? as owner_id, channels.id', [$ownerId])
                ->whereNotIn('channels.id', $this->ownerRows($ownerId)->select('channel_id'))
                ->toBase()
        );
    }

    /** @return int channels shown again */
    public function showForOwner(?int $ownerId, Builder $channels): int
    {
        return $this->ownerRows($ownerId)->whereIn('channel_id', $this->idsOf($channels))->delete();
    }

    /** @return int channels newly hidden */
    public function hideForUser(User $user, Builder $channels): int
    {
        return DB::table('user_hidden_channels')->insertUsing(
            ['user_id', 'channel_id'],
            (clone $channels)
                ->selectRaw('? as user_id, channels.id', [$user->id])
                ->whereNotIn('channels.id', $this->userRows($user)->select('channel_id'))
                ->toBase()
        );
    }

    /** @return int channels shown again */
    public function showForUser(User $user, Builder $channels): int
    {
        return $this->userRows($user)->whereIn('channel_id', $this->idsOf($channels))->delete();
    }

    private function ownerRows(?int $ownerId): QueryBuilder
    {
        return DB::table('owner_hidden_channels')
            ->when($ownerId === null, fn ($q) => $q->whereNull('owner_id'), fn ($q) => $q->where('owner_id', $ownerId));
    }

    private function userRows(User $user): QueryBuilder
    {
        return DB::table('user_hidden_channels')->where('user_id', $user->id);
    }

    /** The matched channel ids as a derived table (MySQL can't delete from a table while selecting the same one). */
    private function idsOf(Builder $channels): QueryBuilder
    {
        return DB::query()->fromSub((clone $channels)->select('channels.id')->toBase(), 'matched')->select('id');
    }
}
