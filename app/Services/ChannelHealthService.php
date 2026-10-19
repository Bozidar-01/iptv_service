<?php

namespace App\Services;

use App\Jobs\CheckStreams;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How well each channel works over time, from two places:
 *
 *  - The stream check: every check counts as working or failed (and slow) in `channel_check_days`, one row per channel and day.
 *    `channels.reliability` is the share of working checks over the last DAYS days (98 = worked in 98% of the checks).
 *  - Viewers: a device that asks for the same channel RETRY_PLAYS times within RETRY_SECONDS is retrying because it does not
 *    play. That counts once per viewer, channel and day as viewer trouble, and asks for a quick check of the channel. Several
 *    viewers with trouble mark the channel on the Channels page, also when the server's own check finds it working (a stream
 *    can work from the server and not from the viewers' country, or answer and still not play).
 *
 * Streams that refresh their playlist through the play link (an HLS media playlist without a master playlist) ask for it every
 * few seconds while they play fine, so they are left out of the viewer count.
 */
class ChannelHealthService
{
    /** Days the reliability score covers. */
    public const DAYS = 7;

    /** Days of check history kept. */
    public const KEEP_DAYS = 30;

    public const RETRY_PLAYS = 3;

    public const RETRY_SECONDS = 60;

    /** Viewers with trouble (today and yesterday) before a channel is marked. */
    public const TROUBLE_VIEWERS = 2;

    /** Minutes between quick checks of one channel that viewers have trouble with. */
    private const QUICK_CHECK_MINUTES = 10;

    /**
     * Count the results of a stream check. Set-based: a few queries for the whole chunk.
     *
     * @param  array<int>  $ok  working channel ids
     * @param  array<int>  $failed
     * @param  array<int>  $slow  working, but arriving slower than they play
     */
    public function recordChecks(array $ok, array $failed, array $slow = []): void
    {
        $all = array_values(array_unique([...$ok, ...$failed]));
        if ($all === []) {
            return;
        }

        $day = today()->toDateString();
        foreach (array_chunk($all, 1000) as $ids) {
            DB::table('channel_check_days')->insertOrIgnore(array_map(fn ($id) => ['channel_id' => $id, 'day' => $day], $ids));
        }

        $this->increment($ok, 'ok', $day);
        $this->increment($failed, 'failed', $day);
        $this->increment($slow, 'slow', $day);
        $this->refreshReliability($all);
    }

    /**
     * Work out `channels.reliability` again for these channels: one query per 1000.
     *
     * @param  array<int>  $ids
     */
    public function refreshReliability(array $ids): void
    {
        $since = today()->subDays(self::DAYS - 1)->toDateString();

        foreach (array_chunk($ids, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            DB::update(
                "UPDATE channels SET reliability = (SELECT ROUND(100.0 * SUM(d.ok) / NULLIF(SUM(d.ok + d.failed), 0)) FROM channel_check_days d WHERE d.channel_id = channels.id AND d.day >= ?) WHERE id IN ({$marks})",
                [$since, ...$chunk]
            );
        }
    }

    /**
     * Count a play request from a device. Returns how many times this device asked for the channel in the last
     * RETRY_SECONDS (1 = first time); from RETRY_PLAYS on it is retrying, and the first time that happens it counts as
     * viewer trouble.
     */
    public function recordPlay(User $user, Channel $channel, string $device): int
    {
        $key = 'plays:'.$user->id.':'.$channel->id.':'.sha1($device);
        Cache::add($key, 0, self::RETRY_SECONDS);
        $count = (int) Cache::increment($key);

        if ($count === self::RETRY_PLAYS && !$this->refreshesThroughPlayLink($channel)) {
            $this->recordTrouble($user, $channel);
        }

        return $this->refreshesThroughPlayLink($channel) ? 1 : $count;
    }

    /**
     * A viewer's device kept retrying: count it (once per viewer, channel and day) and check the channel soon.
     */
    public function recordTrouble(User $user, Channel $channel): void
    {
        $day = today()->toDateString();

        if (Cache::add("viewer-trouble:{$user->id}:{$channel->id}:{$day}", true, now()->endOfDay())) {
            DB::table('channel_check_days')->insertOrIgnore(['channel_id' => $channel->id, 'day' => $day]);
            DB::table('channel_check_days')->where('channel_id', $channel->id)->where('day', $day)->increment('viewer_trouble');
        }

        $source = $channel->source;
        $checkable = $source === null || ($source->check_streams && !$source->isReceiver());
        if ($checkable && Cache::add('quick-check:'.$channel->id, true, now()->addMinutes(self::QUICK_CHECK_MINUTES))) {
            CheckStreams::dispatch([$channel->id]);
        }
    }

    /**
     * Viewers with trouble today and yesterday, per channel (only channels with any).
     *
     * @param  array<int>|null  $ids  limit to these channels
     * @return array<int, int> channel id => viewers
     */
    public function troubleCounts(?array $ids = null): array
    {
        return DB::table('channel_check_days')
            ->where('day', '>=', today()->subDay()->toDateString())
            ->where('viewer_trouble', '>', 0)
            ->when($ids !== null, fn ($q) => $q->whereIn('channel_id', $ids))
            ->groupBy('channel_id')
            ->selectRaw('channel_id, SUM(viewer_trouble) as viewers')
            ->pluck('viewers', 'channel_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** Channels marked because several viewers had trouble (for the Channels filter). */
    public function troubledIds()
    {
        return DB::table('channel_check_days')
            ->where('day', '>=', today()->subDay()->toDateString())
            ->groupBy('channel_id')
            ->havingRaw('SUM(viewer_trouble) >= ?', [self::TROUBLE_VIEWERS])
            ->select('channel_id');
    }

    /**
     * The checks of the last DAYS days for one channel, for the edit page.
     *
     * @return array{ok: int, failed: int, slow: int, viewer_trouble: int}
     */
    public function summary(Channel $channel): array
    {
        $row = DB::table('channel_check_days')->where('channel_id', $channel->id)
            ->where('day', '>=', today()->subDays(self::DAYS - 1)->toDateString())
            ->selectRaw('COALESCE(SUM(ok), 0) as ok, COALESCE(SUM(failed), 0) as failed, COALESCE(SUM(slow), 0) as slow, COALESCE(SUM(viewer_trouble), 0) as viewer_trouble')
            ->first();

        return ['ok' => (int) $row->ok, 'failed' => (int) $row->failed, 'slow' => (int) $row->slow, 'viewer_trouble' => (int) $row->viewer_trouble];
    }

    /** Delete check history older than KEEP_DAYS. */
    public function prune(): int
    {
        return DB::table('channel_check_days')->where('day', '<', today()->subDays(self::KEEP_DAYS)->toDateString())->delete();
    }

    /**
     * An HLS stream whose address is a media playlist (no master playlist, so no picture size was measured): players fetch that
     * playlist again every few seconds, through the play link when it redirects. Many requests are then normal.
     */
    private function refreshesThroughPlayLink(Channel $channel): bool
    {
        return stripos($channel->stream_url, 'm3u8') !== false && $channel->quality_height === null && $channel->quality_kbps === null;
    }

    /**
     * @param  array<int>  $ids
     */
    private function increment(array $ids, string $column, string $day): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            DB::table('channel_check_days')->where('day', $day)->whereIn('channel_id', $chunk)->increment($column);
        }
    }
}
