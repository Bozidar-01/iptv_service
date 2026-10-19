<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A viewer's favourite channels. They show first in the playlist and in Xtream apps, in a "Favourites" category, in the order the
 * viewer chose. Only channels the user can watch count (a channel that is switched off, hidden or out of their bouquets drops out of the
 * list by itself and comes back when it is available again).
 */
class FavoriteService
{
    /** Favourites one user can have. */
    public const MAX = 100;

    /** Name of the category in playlists and apps. */
    public const CATEGORY = 'Favourites';

    /** Category id in the Xtream API (real categories have positive ids, 0 is "General"). */
    public const XTREAM_CATEGORY = '-1';

    /**
     * The user's favourites they can watch now, in order.
     *
     * @return Collection<int, Channel>
     */
    public function channels(User $user): Collection
    {
        $positions = DB::table('user_favorites')->where('user_id', $user->id)->orderBy('position')->orderBy('channel_id')->pluck('position', 'channel_id');
        if ($positions->isEmpty()) {
            return collect();
        }

        return Channel::with('category')->availableTo($user)->whereIn('channels.id', $positions->keys())->get()
            ->sortBy(fn (Channel $c) => sprintf('%05d-%010d', $positions[$c->id], $c->id))->values();
    }

    public function count(User $user): int
    {
        return $this->channels($user)->count();
    }

    /** Add a channel the user can watch, at the end. Returns false when it isn't available to them or the list is full. */
    public function add(User $user, int $channelId): bool
    {
        if (!Channel::availableTo($user)->whereKey($channelId)->exists()) {
            return false;
        }
        $rows = DB::table('user_favorites')->where('user_id', $user->id);
        if ((clone $rows)->where('channel_id', $channelId)->exists()) {
            return true;
        }
        if ((clone $rows)->count() >= self::MAX) {
            return false;
        }

        DB::table('user_favorites')->insert(['user_id' => $user->id, 'channel_id' => $channelId, 'position' => ((int) (clone $rows)->max('position')) + 1]);

        return true;
    }

    public function remove(User $user, int $channelId): void
    {
        DB::table('user_favorites')->where('user_id', $user->id)->where('channel_id', $channelId)->delete();
    }

    /** Move one place up (-1) or down (+1) in the list the viewer sees; the positions are renumbered 1…n. */
    public function move(User $user, int $channelId, int $direction): void
    {
        $ids = $this->channels($user)->pluck('id')->all();
        $index = array_search($channelId, $ids, true);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);
        if ($target === null || !isset($ids[$target])) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        foreach ($ids as $position => $id) {
            DB::table('user_favorites')->where('user_id', $user->id)->where('channel_id', $id)->update(['position' => $position + 1]);
        }
    }

    /** Channels the user can watch whose name contains the text, not yet favourites: for adding. */
    public function search(User $user, string $text, int $limit = 20): Collection
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return collect();
        }

        return Channel::availableTo($user)->where('channels.name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $text).'%')
            ->whereNotIn('channels.id', DB::table('user_favorites')->select('channel_id')->where('user_id', $user->id))
            ->orderBy('channels.name')->limit($limit)->get(['channels.id', 'channels.name']);
    }
}
