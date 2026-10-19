<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\DB;

/**
 * Fixed channel numbers (`channels.number`): sent as tvg-chno in playlists and as `num` in the Xtream API, so "101" stays the same
 * channel after every reload and viewers can use the number keys on the remote.
 *
 * Numbers never change by themselves: new channels get the next free numbers (after an import, or when added by hand), and the
 * admin can number everything again in playlist order (Playlist options) or type a number on the channel form. A number may be
 * used twice when the admin types it so; apps then show both.
 */
class ChannelNumberService
{
    /**
     * Number every channel again, 1, 2, 3… in playlist order (category, position in the source, name).
     *
     * @return int channels numbered
     */
    public function renumber(): int
    {
        $ids = Channel::query()->playlistOrder()->pluck('channels.id')->all();
        $this->assign($ids, 1);
        ListCacheService::bump();

        return count($ids);
    }

    /**
     * Give channels without a number the next free numbers, in playlist order.
     *
     * @return int channels numbered
     */
    public function fillMissing(): int
    {
        $ids = Channel::query()->whereNull('number')->playlistOrder()->pluck('channels.id')->all();
        if ($ids === []) {
            return 0;
        }

        $this->assign($ids, (int) Channel::max('number') + 1);
        ListCacheService::bump();

        return count($ids);
    }

    /**
     * @param  array<int>  $ids  in the order they get numbers
     */
    private function assign(array $ids, int $first): void
    {
        $number = $first;
        $now = now();

        foreach (array_chunk($ids, 1000) as $chunk) {
            $cases = implode(' ', array_fill(0, count($chunk), 'WHEN ? THEN ?'));
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $bindings = [];
            foreach ($chunk as $id) {
                array_push($bindings, $id, $number++);
            }

            DB::update("UPDATE channels SET number = CASE id {$cases} END, updated_at = ? WHERE id IN ({$marks})", [...$bindings, $now, ...$chunk]);
        }
    }
}
