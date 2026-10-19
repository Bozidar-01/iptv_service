<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Collection;

/**
 * Picks the stream to play instead when a channel's own stream is down, slow, or keeps failing for a viewer.
 *
 * Candidates are copies of the same channel: the admin's chosen backup, and every other channel with the same tvg-id (the same
 * programme from another source or another entry). A candidate must be enabled, not playing a backup itself, and working.
 * They are ranked best first:
 *  1. the admin's chosen backup;
 *  2. not slow (the last check found it arrives at least as fast as it plays);
 *  3. most reliable over the last 7 days (ChannelHealthService), unknown counts as 50%;
 *  4. best picture (ChannelDuplicateService::qualityOf);
 *  5. lowest id, so the choice is stable.
 */
class ChannelFailoverService
{
    /** Below this, a stream arrives slower than it plays (`channels.stream_speed`, percent). */
    public const SLOW_BELOW = 100;

    private const COLUMNS = ['id', 'name', 'source_id', 'tvg_id', 'is_active', 'stream_status', 'reliability', 'stream_speed', 'quality_height', 'quality_kbps', 'using_backup_id'];

    /**
     * The best working copy for each channel, if there is one.
     *
     * @param  Collection<int, Channel>  $channels  with id, tvg_id and backup_channel_id
     * @param  array<int>  $workingNow  ids the running check found working (their stored status may still say offline)
     * @param  array<int>  $exclude  ids that can't be a backup (the ones found down, or slow, in this check)
     * @param  bool  $notSlow  only copies the last check did not find slow
     * @return array<int, int> channel id => backup id
     */
    public function bestFor(Collection $channels, array $workingNow = [], array $exclude = [], bool $notSlow = false): array
    {
        if ($channels->isEmpty()) {
            return [];
        }

        $candidates = $this->candidates($channels, $workingNow, [...$exclude, ...$channels->pluck('id')->all()]);
        $best = [];

        foreach ($channels as $channel) {
            $ranked = $this->rank($this->copiesOf($channel, $candidates), $channel->backup_channel_id)
                ->when($notSlow, fn ($copies) => $copies->reject(fn (Channel $c) => self::isSlow($c->stream_speed)));
            if ($ranked->isNotEmpty()) {
                $best[$channel->id] = $ranked->first()->id;
            }
        }

        return $best;
    }

    /**
     * The working copies of one channel, best first (for the edit page and for a viewer whose device keeps retrying).
     *
     * @param  array<int>  $exclude
     * @return Collection<int, Channel>
     */
    public function ranked(Channel $channel, array $exclude = []): Collection
    {
        $candidates = $this->candidates(collect([$channel]), [], [...$exclude, $channel->id]);

        return $this->rank($this->copiesOf($channel, $candidates), $channel->backup_channel_id)->values();
    }

    /**
     * For a viewer whose device keeps asking for the channel (it does not play for them): the stream to try on this attempt.
     * Goes through the working copies one by one, best first, and back to the channel's own stream, so one bad copy does not
     * keep a viewer stuck.
     *
     * @param  int  $attempt  how many times the device asked in a row (ChannelHealthService::recordPlay), from RETRY_PLAYS on
     */
    public function forRetry(Channel $channel, int $attempt): Channel
    {
        $playing = $channel->playbackChannel();
        $copies = $this->ranked($channel, [$playing->id]);

        if ($copies->isEmpty()) {
            return $playing;
        }

        $order = $copies->push($playing)->values();

        return $order[($attempt - ChannelHealthService::RETRY_PLAYS) % $order->count()];
    }

    public static function isSlow(?int $speed): bool
    {
        return $speed !== null && $speed < self::SLOW_BELOW;
    }

    /**
     * @param  Collection<int, Channel>  $channels
     * @param  array<int>  $workingNow
     * @param  array<int>  $exclude
     * @return Collection<int, Channel>
     */
    private function candidates(Collection $channels, array $workingNow, array $exclude): Collection
    {
        // As stored and in lower case: MySQL compares without case, SQLite (tests) does not
        $tvgIds = $channels->pluck('tvg_id')->filter()->flatMap(fn ($id) => [$id, mb_strtolower($id)])->unique()->values()->all();
        $chosenIds = $channels->pluck('backup_channel_id')->filter()->unique()->values()->all();

        if ($tvgIds === [] && $chosenIds === []) {
            return collect();
        }

        return Channel::query()
            ->where(fn ($q) => $q->whereIn('id', $chosenIds)->orWhereIn('tvg_id', $tvgIds))
            ->whereNotIn('id', array_values(array_unique($exclude)))
            ->where('is_active', true)
            ->whereNull('using_backup_id')
            ->where(fn ($q) => $q->where('stream_status', StreamCheckService::STATUS_ONLINE)->orWhereIn('id', $workingNow))
            ->with('source:id,name,type')
            ->get(self::COLUMNS);
    }

    /**
     * @param  Collection<int, Channel>  $candidates
     * @return Collection<int, Channel>
     */
    private function copiesOf(Channel $channel, Collection $candidates): Collection
    {
        $tvg = $channel->tvg_id ? mb_strtolower($channel->tvg_id) : null;

        return $candidates->filter(fn (Channel $c) => $c->id !== $channel->id
            && ($c->id === $channel->backup_channel_id || ($tvg !== null && mb_strtolower((string) $c->tvg_id) === $tvg)));
    }

    /**
     * @param  Collection<int, Channel>  $copies
     * @return Collection<int, Channel>
     */
    private function rank(Collection $copies, ?int $chosenId): Collection
    {
        return $copies->sortBy(fn (Channel $c) => [
            $c->id === $chosenId ? 0 : 1,
            self::isSlow($c->stream_speed) ? 1 : 0,
            -($c->reliability ?? 50),
            -ChannelDuplicateService::qualityOf($c->name, $c->quality_height),
            $c->id,
        ])->values();
    }
}
