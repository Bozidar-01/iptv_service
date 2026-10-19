<?php

namespace App\Services;

use App\Jobs\CheckStreams;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamAlert;
use App\Models\StreamLog;
use App\Support\CappedStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\TransferStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Checks whether channel streams answer, and switches dead channels off.
 *
 * A channel the check switches off gets auto_disabled = true and is switched back on
 * when a later check finds its stream working. Channels an admin disabled are not checked.
 * Failed streams are tried a second time before they count as offline, and the reason is
 * kept in stream_error (see describeError()).
 * For watched channels (see Channel::scopeWatched) a change of state also creates a StreamAlert.
 *
 * Backups: when a stream is down, or arrives slower than it plays, and another working copy of the channel exists (the admin's
 * chosen backup_channel_id, or a channel with the same tvg-id; ranked by ChannelFailoverService), the channel stays on and plays
 * that stream instead (using_backup_id) until its own stream works well again.
 *
 * HLS streams that answer get a closer look (inspectHls): the playlist with the picture must list video, it must move on between
 * checks (else the picture has stopped: "frozen"), and for channels people watch, one piece of video is downloaded to see whether
 * it arrives faster than it plays (stream_speed, percent). Every result is counted for the reliability score (ChannelHealthService).
 */
class StreamCheckService
{
    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    /** Channels per queued job; with the limits below a job takes about a minute at most. */
    public const CHUNK_SIZE = 250;

    /** Seconds to wait before trying failed streams again. */
    public const RETRY_DELAY = 5;

    private const CONCURRENCY = 50;

    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 6;

    /** Kept of each stream's first answer: a whole master playlist (some have very long addresses), or a moment of a live stream. */
    private const MAX_BYTES = 65536;

    /** Enough for the playlist of one picture size, also a long one. */
    private const PLAYLIST_BYTES = 65536;

    /** Downloaded of one piece of video to measure the speed. */
    private const SEGMENT_BYTES = 1048576;

    /** Picture sizes of a master playlist tried before it counts as having no video. */
    private const MAX_VARIANTS = 3;

    /** Days back a play makes a channel "watched by people", so its speed is measured. */
    private const POPULAR_DAYS = 7;

    private const USER_AGENT = 'VLC/3.0.20 LibVLC/3.0.20';

    /** Cache counter of queued check jobs not finished yet (see queueCheck). */
    public const PENDING_KEY = 'stream-check:pending';

    /**
     * Channels the check looks at: enabled ones, and ones it switched off itself, except
     * channels of sources with check_streams turned off, and receiver channels (checking them
     * would tune the receiver; ReceiverService checks the relay instead).
     *
     * @return Builder<Channel>
     */
    public function checkableQuery(?int $sourceId = null, bool $watchedOnly = false): Builder
    {
        return Channel::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('auto_disabled', true))
            ->where(fn ($q) => $q->whereNull('source_id')
                ->orWhereIn('source_id', Source::select('id')->where('check_streams', true)->where('type', '!=', 'receiver')))
            ->when($sourceId, fn ($q) => $q->where('source_id', $sourceId))
            // The frequent check also follows channels playing a backup, so they go back to their own stream soon
            ->when($watchedOnly, fn ($q) => $q->where(fn ($q) => $q->watched()->orWhereNotNull('channels.using_backup_id')));
    }

    /**
     * Queue a check of every checkable channel, in chunks.
     *
     * Only one check at a time: while jobs of an earlier check are still queued or running,
     * nothing is queued (so pressing the button twice, or a stopped queue worker, doesn't pile
     * up checks). The counter expires after 2 hours in case a worker died mid-check.
     *
     * @return ?int number of channels queued, null when a check is still running
     */
    public function queueCheck(?int $sourceId = null, bool $watchedOnly = false): ?int
    {
        if ($this->isRunning()) {
            return null;
        }

        $chunks = [];
        $this->checkableQuery($sourceId, $watchedOnly)->select('id')->chunkById(self::CHUNK_SIZE, function ($channels) use (&$chunks) {
            $chunks[] = $channels->pluck('id')->all();
        });

        if ($chunks !== []) {
            Cache::put(self::PENDING_KEY, count($chunks), now()->addHours(2));
            foreach ($chunks as $ids) {
                CheckStreams::dispatch($ids);
            }
        }

        return array_sum(array_map('count', $chunks));
    }

    public function isRunning(): bool
    {
        return (int) Cache::get(self::PENDING_KEY, 0) > 0;
    }

    /**
     * Called by each CheckStreams job when it ends, successfully or not.
     */
    public function jobFinished(): void
    {
        $left = Cache::decrement(self::PENDING_KEY);
        if ($left <= 0) {
            Cache::forget(self::PENDING_KEY);
        }

        // The last job of a full check (not a quick check of one channel): which copies are better may have changed
        if ($left === 0 && app(PlaylistOptionsService::class)->settings()['hide_worse_copies']) {
            app(PlaylistOptionsService::class)->refreshBetterCopies();
        }
    }

    /**
     * Check the given channels and save the result.
     *
     * @param  array<int>  $ids
     * @return array{online: int, offline: int}
     */
    public function checkChannels(array $ids): array
    {
        $channels = $this->checkableQuery()->whereIn('id', $ids)
            ->with('source:id,alert_on_down')
            ->get(['id', 'name', 'source_id', 'stream_url', 'http_user_agent', 'http_referrer', 'stream_status', 'stream_checked_at', 'alert_on_down', 'tvg_id', 'backup_channel_id', 'hls_sequence']);

        $this->forgetFound();
        $errors = $this->probe($channels);

        // A single timeout or hiccup shouldn't switch a channel off: try the failed ones once more
        $failed = $channels->filter(fn ($channel) => $errors[$channel->id] !== null);
        if ($failed->isNotEmpty()) {
            Sleep::for(self::RETRY_DELAY)->seconds();
            $errors = array_replace($errors, $this->probe($failed));
        }

        // HLS streams that answer: is there video in them, is it moving, and is it fast enough
        $hls = $channels->filter(fn ($channel) => $errors[$channel->id] === null && isset($this->bodies[$channel->id]));
        $inspected = $this->inspectHls($hls);
        $badHls = $hls->filter(fn ($channel) => ($inspected[$channel->id]['error'] ?? null) !== null);
        if ($badHls->isNotEmpty()) {
            Sleep::for(self::RETRY_DELAY)->seconds();
            $inspected = array_replace($inspected, $this->inspectHls($badHls));
        }
        foreach ($inspected as $id => $result) {
            $errors[$id] = $result['error'];
        }

        $this->recordAlerts($channels, $errors);

        $online = array_keys(array_filter($errors, fn ($error) => $error === null));
        $offline = array_keys(array_filter($errors, fn ($error) => $error !== null));
        $speeds = array_map(fn ($r) => $r['speed'], array_intersect_key($inspected, array_flip($online)));
        $slow = array_keys(array_filter($speeds, fn ($speed) => ChannelFailoverService::isSlow($speed)));

        $failover = app(ChannelFailoverService::class);
        $backups = $failover->bestFor($channels->whereIn('id', $offline), array_values(array_diff($online, $slow)), [...$offline, ...$slow]);
        // A slow stream only moves to a copy that is not slow too
        $fasterCopies = $failover->bestFor($channels->whereIn('id', $slow), array_values(array_diff($online, $slow)), [...$offline, ...$slow], notSlow: true);
        $withoutBackup = array_values(array_diff($offline, array_keys($backups)));
        $now = now();

        // Only flip channels the check owns: switch off enabled dead ones without a backup,
        // switch back on recovered ones and ones that now have a backup
        Channel::whereIn('id', $withoutBackup)->where('is_active', true)->update(['is_active' => false, 'auto_disabled' => true]);
        Channel::whereIn('id', [...$online, ...array_keys($backups)])->where('auto_disabled', true)->update(['is_active' => true, 'auto_disabled' => false]);
        Channel::whereIn('id', $online)->update(['stream_status' => self::STATUS_ONLINE, 'stream_error' => null, 'stream_checked_at' => $now, 'using_backup_id' => null]);
        Channel::whereIn('id', $withoutBackup)->update(['using_backup_id' => null]);
        $this->saveQualities($online);
        $this->setColumn('stream_speed', array_filter($speeds, fn ($speed) => $speed !== null));
        // The position in a live playlist, to see next time whether it moved on; only for streams whose playlist was read
        $this->setColumn('hls_sequence', array_map(fn ($r) => $r['sequence'], array_intersect_key($inspected, array_flip($online))));
        $this->setBackups($backups + $fasterCopies);

        // One update per distinct reason (a handful), not per channel
        collect($errors)->reject(fn ($error) => $error === null)
            ->groupBy(fn ($error) => $error, preserveKeys: true)
            ->each(fn ($group, $error) => Channel::whereIn('id', $group->keys())
                ->update(['stream_status' => self::STATUS_OFFLINE, 'stream_error' => $error, 'stream_checked_at' => $now]));

        app(ChannelHealthService::class)->recordChecks($online, $offline, $slow);

        return ['online' => count($online), 'offline' => count($offline)];
    }

    /**
     * @param  array<int, int>  $backups  channel id => backup id; one query for all
     */
    private function setBackups(array $backups): void
    {
        if ($backups === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($backups), 'WHEN ? THEN ?'));
        $placeholders = implode(',', array_fill(0, count($backups), '?'));
        $bindings = [];
        foreach ($backups as $id => $backupId) {
            array_push($bindings, $id, $backupId);
        }

        DB::update(
            "UPDATE channels SET using_backup_id = CASE id {$cases} END WHERE id IN ({$placeholders})",
            [...$bindings, ...array_keys($backups)]
        );
    }

    /**
     * Alert on watched channels that went down (including the first check) or came back.
     *
     * @param  Collection<int, Channel>  $channels  with their state before this check
     * @param  array<int, ?string>  $errors  null = online
     */
    private function recordAlerts(Collection $channels, array $errors): void
    {
        $now = now();
        $rows = [];

        foreach ($channels as $channel) {
            if (!$channel->isWatched()) {
                continue;
            }

            $online = $errors[$channel->id] === null;
            $wasOffline = $channel->stream_status === self::STATUS_OFFLINE;
            $type = match (true) {
                !$online && !$wasOffline => StreamAlert::TYPE_DOWN,
                $online && $wasOffline => StreamAlert::TYPE_UP,
                default => null,
            };

            if ($type) {
                $rows[] = [
                    'type' => $type,
                    'channel_id' => $channel->id,
                    'source_id' => $channel->source_id,
                    'name' => $channel->name,
                    'detail' => $errors[$channel->id],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            StreamAlert::insert($rows);
        }
    }

    /**
     * Request every stream concurrently and keep only the first few KB of each.
     *
     * @param  Collection<int, Channel>  $channels
     * @return array<int, ?string> channel id => error code, null when online
     */
    public function probe(Collection $channels): array
    {
        $sinks = [];
        $heads = [];

        $responses = Http::pool(function (Pool $pool) use ($channels, &$sinks, &$heads) {
            foreach ($channels as $channel) {
                $id = $channel->id;
                $sinks[$id] = new CappedStream(self::MAX_BYTES);

                $pool->as((string) $id)
                    ->withUserAgent($channel->http_user_agent ?: self::USER_AGENT)
                    ->withHeaders(array_filter(['Referer' => $channel->http_referrer]))
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->withOptions([
                        'sink' => $sinks[$id],
                        // Called for every response, redirects included; the last one is the real stream
                        'on_headers' => function ($response) use ($id, &$sinks, &$heads) {
                            $sinks[$id]->clear();
                            $heads[$id] = [$response->getStatusCode(), $response->getHeaderLine('Content-Type')];
                        },
                        // Where the stream really is after redirects: addresses inside a playlist are relative to it
                        'on_stats' => function (TransferStats $stats) use ($id) {
                            $this->finalUrls[$id] = (string) $stats->getEffectiveUri();
                        },
                    ])
                    ->get($channel->stream_url);
            }
        }, self::CONCURRENCY);

        $errors = [];

        foreach ($channels as $channel) {
            $id = $channel->id;
            $response = $responses[(string) $id] ?? null;

            // A master playlist tells the picture sizes on offer: keep the best for the channels that work
            $body = $response instanceof Response ? $response->body() : (isset($heads[$id]) ? $sinks[$id]->contents() : '');
            if (($quality = self::parseQuality($body)) !== null) {
                $this->qualities[$id] = $quality;
            }

            $errors[$id] = match (true) {
                $response instanceof Response => $this->diagnose($response->status(), $response->header('Content-Type'), $response->body()),
                // Aborted on purpose once the sink was full (or timed out mid-stream): judge what arrived
                isset($heads[$id]) => $this->diagnose($heads[$id][0], $heads[$id][1], $sinks[$id]->contents()),
                default => $this->connectionError($response),
            };

            // HLS playlists get a closer look (inspectHls); a playlist cut off at MAX_BYTES loses its last, unfinished line
            if ($errors[$id] === null && str_contains($body, '#EXTM3U')) {
                $this->bodies[$id] = strlen($body) >= self::MAX_BYTES ? substr($body, 0, (int) strrpos($body, "\n")) : $body;
            } else {
                unset($this->bodies[$id]);
            }
        }

        return $errors;
    }

    /** What probe() found for an earlier chunk of channels. */
    private function forgetFound(): void
    {
        $this->qualities = $this->bodies = $this->finalUrls = [];
    }

    /** @var array<int, string> channel id => the playlist the last probe() got, for HLS streams */
    private array $bodies = [];

    /** @var array<int, string> channel id => address after redirects */
    private array $finalUrls = [];

    /**
     * A closer look at HLS streams that answered: the playlist of the best picture size must list video and move on between checks;
     * for channels people watch (played in the last POPULAR_DAYS days, or with alerts on) the newest piece of video is downloaded
     * (the first SEGMENT_BYTES of it) to see how fast it arrives.
     *
     * @param  Collection<int, Channel>  $channels  with hls_sequence and stream_checked_at as before this check
     * @return array<int, array{error: ?string, speed: ?int, sequence: ?int}>
     */
    public function inspectHls(Collection $channels): array
    {
        if ($channels->isEmpty()) {
            return [];
        }

        $byId = $channels->keyBy('id');
        $results = [];
        $variants = [];

        foreach ($channels as $channel) {
            $results[$channel->id] = ['error' => null, 'speed' => null, 'sequence' => null];
            $body = $this->bodies[$channel->id] ?? '';
            $base = $this->finalUrls[$channel->id] ?? $channel->stream_url;

            $variants[$channel->id] = str_contains($body, '#EXT-X-STREAM-INF')
                ? array_map(fn ($v) => [self::resolveUrl($base, $v[0]), $v[1]], self::variants($body))
                : [[$base, null]];
            if ($variants[$channel->id] === []) {
                unset($variants[$channel->id]);
            }
        }

        // The best picture size first; when its playlist is missing or empty, the next one (players switch to what works)
        $playlists = $chosen = [];
        $pending = array_map(fn () => 0, $variants);
        for ($try = 0; $try < self::MAX_VARIANTS && $pending !== []; $try++) {
            $urls = [];
            foreach ($pending as $id => $index) {
                $urls[$id] = $variants[$id][$index][0];
            }
            $answers = $this->download($byId, $urls, self::PLAYLIST_BYTES);
            $next = [];
            foreach ($answers as $id => $playlist) {
                $playlists[$id] = $playlist;
                $chosen[$id] = $pending[$id];
                $failed = $playlist['status'] !== null && ($playlist['status'] >= 400 || !self::hasVideo($playlist['body']));
                if ($failed && isset($variants[$id][$pending[$id] + 1])) {
                    $next[$id] = $pending[$id] + 1;
                }
            }
            $pending = $next;
        }

        $popular = $this->popularIds($channels);
        $segmentRequests = $segmentInfo = [];

        foreach ($playlists as $id => $playlist) {
            // No answer at all (a provider may allow only so many connections at once): don't judge; a master inside a
            // master playlist: leave it to the player
            if ($playlist['status'] === null || str_contains($playlist['body'], '#EXT-X-STREAM-INF')) {
                continue;
            }

            // An error, or a playlist without any video in it: players can't show anything
            $media = self::parseMediaPlaylist($playlist['body']);
            if ($playlist['status'] >= 400 || $media === null || $media['segment'] === null) {
                $results[$id]['error'] = 'no-video';

                continue;
            }

            $results[$id]['sequence'] = $media['sequence'];
            if ($this->isFrozen($byId[$id], $media)) {
                $results[$id]['error'] = 'frozen';

                continue;
            }

            if (isset($popular[$id])) {
                $segmentRequests[$id] = self::resolveUrl($playlist['url'] ?: $variants[$id][$chosen[$id]][0], $media['segment']);
                $segmentInfo[$id] = [$media['duration'], $variants[$id][$chosen[$id]][1]];
            }
        }

        foreach ($this->download($byId, $segmentRequests, self::SEGMENT_BYTES) as $id => $segment) {
            // Video that does not arrive is as bad as very slow video, but it does not switch the channel off: the playlist works,
            // and a provider may refuse the extra connection of the check
            $results[$id]['speed'] = $segment['status'] === null || $segment['status'] >= 400
                ? 0
                : $this->segmentSpeed($segment, $segmentInfo[$id][0], $segmentInfo[$id][1]);
        }

        return $results;
    }

    private static function hasVideo(string $body): bool
    {
        $media = self::parseMediaPlaylist($body);

        return str_contains($body, '#EXT-X-STREAM-INF') || ($media !== null && $media['segment'] !== null);
    }

    /**
     * How fast a piece of video arrives compared to how long it plays, in percent (100 = just in time, under 100 the viewer
     * waits). From the download: time to the first byte, then the rate of the rest, scaled to the whole piece (its size, else
     * the bitrate the playlist states). Null when it can't be told.
     *
     * @param  array{status: ?int, body: string, url: string, length: ?int, stats: array<string, mixed>}  $segment
     */
    protected function segmentSpeed(array $segment, float $duration, ?int $bandwidth): ?int
    {
        $stats = $segment['stats'];
        $bytes = (int) ($stats['size_download'] ?? strlen($segment['body']));
        $complete = $bytes < self::SEGMENT_BYTES;
        $fullSize = $complete ? $bytes : ($segment['length'] ?? ($bandwidth ? (int) ($bandwidth * $duration / 8) : null));

        return self::speedPercent($bytes, (float) ($stats['starttransfer_time'] ?? 0), (float) ($stats['total_time'] ?? 0), $fullSize, $duration);
    }

    /**
     * @param  int  $bytes  downloaded
     * @param  float  $firstByte  seconds until the first byte
     * @param  float  $total  seconds for the whole download
     * @param  ?int  $fullSize  bytes of the whole piece of video
     * @param  float  $duration  seconds the piece plays
     */
    public static function speedPercent(int $bytes, float $firstByte, float $total, ?int $fullSize, float $duration): ?int
    {
        $transfer = $total - $firstByte;
        if ($bytes <= 0 || $fullSize === null || $fullSize <= 0 || $duration <= 0 || $total <= 0) {
            return null;
        }

        // A tiny piece arrives in one go: no rate to measure, the first byte is all the wait
        $needed = $transfer > 0.001 ? $firstByte + $fullSize / ($bytes / $transfer) : $total;

        return (int) min(999, round(100 * $duration / max($needed, 0.001)));
    }

    /**
     * The picture has stopped: a live playlist (no end marker) still starts at the same piece of video as at the last check,
     * long enough ago that it should have moved on.
     *
     * @param  array{sequence: ?int, target: int, ended: bool}  $media
     */
    private function isFrozen(Channel $channel, array $media): bool
    {
        if ($media['ended'] || $media['sequence'] === null || $channel->hls_sequence === null || $channel->stream_checked_at === null) {
            return false;
        }

        return $media['sequence'] === (int) $channel->hls_sequence
            && $channel->stream_checked_at->diffInSeconds(now()) >= max(60, 3 * $media['target']);
    }

    /**
     * The picture sizes of a master playlist: [address, BANDWIDTH in bit/s or null], highest bandwidth first.
     *
     * @return list<array{0: string, 1: ?int}>
     */
    public static function variants(string $body): array
    {
        $variants = [];
        $lines = preg_split('/\R/', $body);

        foreach ($lines as $i => $line) {
            if (!str_starts_with($line, '#EXT-X-STREAM-INF')) {
                continue;
            }
            $bandwidth = preg_match('/(?<![A-Z-])BANDWIDTH=(\d+)/i', $line, $m) ? (int) $m[1] : null;
            // The address is the next line that is not a tag
            for ($j = $i + 1; $j < count($lines); $j++) {
                $uri = trim($lines[$j]);
                if ($uri !== '' && !str_starts_with($uri, '#')) {
                    $variants[] = [$uri, $bandwidth];

                    break;
                }
            }
        }

        usort($variants, fn ($a, $b) => (int) $b[1] <=> (int) $a[1]);

        return $variants;
    }

    /**
     * What a media playlist says: where it starts (EXT-X-MEDIA-SEQUENCE, null without the tag), the target length of a piece,
     * whether it has ended, and the newest piece of video with its length. Null when it isn't a media playlist.
     *
     * @return array{sequence: ?int, target: int, ended: bool, segment: ?string, duration: float}|null
     */
    public static function parseMediaPlaylist(string $body): ?array
    {
        if (!str_contains($body, '#EXTM3U') || str_contains($body, '#EXT-X-STREAM-INF')) {
            return null;
        }

        $sequence = preg_match('/^#EXT-X-MEDIA-SEQUENCE:\s*(\d+)/m', $body, $m) ? (int) $m[1] : null;
        $target = preg_match('/^#EXT-X-TARGETDURATION:\s*(\d+)/m', $body, $m) ? (int) $m[1] : 10;
        $segment = null;
        $duration = (float) $target;
        $lastExtinf = null;

        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXTINF:')) {
                $lastExtinf = (float) substr($line, 8);
            } elseif ($line !== '' && !str_starts_with($line, '#') && $lastExtinf !== null) {
                $segment = $line;
                $duration = $lastExtinf > 0 ? $lastExtinf : (float) $target;
                $lastExtinf = null;
            }
        }

        return ['sequence' => $sequence, 'target' => max(1, $target), 'ended' => str_contains($body, '#EXT-X-ENDLIST'), 'segment' => $segment, 'duration' => $duration];
    }

    /** An address inside a playlist, made absolute against the playlist's own address ("../x.ts" included). */
    public static function resolveUrl(string $base, string $uri): string
    {
        return (string) UriResolver::resolve(new Uri($base), new Uri($uri));
    }

    /**
     * Download the start of several addresses at once (with each channel's user agent and referrer).
     *
     * @param  Collection<int, Channel>  $channels  keyed by id
     * @param  array<int, string>  $urls  channel id => address
     * @return array<int, array{status: ?int, body: string, url: string, length: ?int, stats: array<string, mixed>}>
     */
    private function download(Collection $channels, array $urls, int $maxBytes): array
    {
        if ($urls === []) {
            return [];
        }

        $sinks = $heads = $stats = [];

        $responses = Http::pool(function (Pool $pool) use ($channels, $urls, $maxBytes, &$sinks, &$heads, &$stats) {
            foreach ($urls as $id => $url) {
                $channel = $channels[$id];
                $sinks[$id] = new CappedStream($maxBytes);

                $pool->as((string) $id)
                    ->withUserAgent($channel->http_user_agent ?: self::USER_AGENT)
                    ->withHeaders(array_filter(['Referer' => $channel->http_referrer]))
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->withOptions([
                        'sink' => $sinks[$id],
                        'on_headers' => function ($response) use ($id, &$sinks, &$heads) {
                            $sinks[$id]->clear();
                            $length = $response->getHeaderLine('Content-Length');
                            $heads[$id] = [$response->getStatusCode(), is_numeric($length) ? (int) $length : null];
                        },
                        'on_stats' => function (TransferStats $transfer) use ($id, &$stats) {
                            $stats[$id] = ['url' => (string) $transfer->getEffectiveUri(), 'handler' => $transfer->getHandlerStats()];
                        },
                    ])
                    ->get($url);
            }
        }, self::CONCURRENCY);

        $results = [];
        foreach ($urls as $id => $url) {
            $response = $responses[(string) $id] ?? null;
            $body = $response instanceof Response ? $response->body() : (isset($heads[$id]) ? $sinks[$id]->contents() : '');
            // Cut off at $maxBytes (a long live playlist keeps hours of video): the last, unfinished line is dropped
            if (strlen($body) >= $maxBytes && str_contains($body, '#EXTM3U')) {
                $body = substr($body, 0, (int) strrpos($body, "\n"));
            }
            $results[$id] = [
                'status' => $response instanceof Response ? $response->status() : ($heads[$id][0] ?? null),
                'body' => $body,
                'url' => $stats[$id]['url'] ?? $url,
                'length' => $response instanceof Response ? (is_numeric($response->header('Content-Length')) ? (int) $response->header('Content-Length') : null) : ($heads[$id][1] ?? null),
                'stats' => $stats[$id]['handler'] ?? [],
            ];
        }

        return $results;
    }

    /**
     * Of these channels, the ones people watch: played in the last POPULAR_DAYS days, or with alerts on.
     *
     * @param  Collection<int, Channel>  $channels
     * @return array<int, true>
     */
    private function popularIds(Collection $channels): array
    {
        $played = StreamLog::whereIn('channel_id', $channels->pluck('id'))
            ->where('started_at', '>', now()->subDays(self::POPULAR_DAYS))
            ->distinct()->pluck('channel_id')->all();
        $watched = $channels->filter(fn (Channel $c) => $c->isWatched())->pluck('id')->all();

        return array_fill_keys([...$played, ...$watched], true);
    }

    /**
     * Set one column for many channels: one query (null values allowed).
     *
     * @param  array<int, int|string|null>  $values  channel id => value
     */
    private function setColumn(string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($values), 'WHEN ? THEN ?'));
        $marks = implode(',', array_fill(0, count($values), '?'));
        $bindings = [];
        foreach ($values as $id => $value) {
            array_push($bindings, $id, $value);
        }

        DB::update("UPDATE channels SET {$column} = CASE id {$cases} END WHERE id IN ({$marks})", [...$bindings, ...array_keys($values)]);
    }

    /** @var array<int, array{0: int, 1: int}> channel id => [height, kbps] found by the last probe() */
    private array $qualities = [];

    /**
     * The best picture of an HLS master playlist: the tallest RESOLUTION and the highest BANDWIDTH, or null when the text is not a
     * master playlist (a media playlist, a raw stream or an error page tells nothing).
     *
     * @return array{0: int, 1: int}|null [height in pixels, bitrate in kbps]
     */
    public static function parseQuality(string $body): ?array
    {
        if (!str_contains($body, '#EXT-X-STREAM-INF')) {
            return null;
        }

        $height = $kbps = 0;
        foreach (preg_split('/\R/', $body) as $line) {
            if (!str_starts_with($line, '#EXT-X-STREAM-INF')) {
                continue;
            }
            if (preg_match('/RESOLUTION=\d+x(\d+)/i', $line, $m)) {
                $height = max($height, (int) $m[1]);
            }
            if (preg_match('/(?<![A-Z-])BANDWIDTH=(\d+)/i', $line, $m)) {
                $kbps = max($kbps, intdiv((int) $m[1], 1000));
            }
        }

        return $height > 0 || $kbps > 0 ? [min($height, 65000), $kbps] : null;
    }

    /**
     * Save what the last probe() found for these (working) channels: one query for all.
     *
     * @param  array<int>  $ids
     */
    private function saveQualities(array $ids): void
    {
        $found = array_intersect_key($this->qualities, array_flip($ids));
        if ($found === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($found), 'WHEN ? THEN ?'));
        $marks = implode(',', array_fill(0, count($found), '?'));
        $bindings = [];
        foreach ($found as $id => [$height]) {
            array_push($bindings, $id, $height);
        }
        foreach ($found as $id => [, $kbps]) {
            array_push($bindings, $id, $kbps);
        }

        DB::update("UPDATE channels SET quality_height = CASE id {$cases} END, quality_kbps = CASE id {$cases} END WHERE id IN ({$marks})", [...$bindings, ...array_keys($found)]);
    }

    /**
     * Why a response is not a working stream, or null when it is one: 2xx with a playlist
     * or media, not an HTML page.
     */
    public function diagnose(int $status, string $contentType, string $body): ?string
    {
        if ($status < 200 || $status >= 300) {
            return 'http:'.$status;
        }

        if (str_contains($body, '#EXTM3U')) {
            return null;
        }

        if (stripos($contentType, 'html') !== false || preg_match('/^\s*</', $body)) {
            return 'html';
        }

        if (preg_match('/mpegurl|video|audio|octet-stream|mp2t|dash/i', $contentType)) {
            return null;
        }

        return strlen($body) >= 188 ? null : 'empty'; // 188 bytes = one MPEG-TS packet
    }

    public function isOnline(int $status, string $contentType, string $body): bool
    {
        return $this->diagnose($status, $contentType, $body) === null;
    }

    private function connectionError(mixed $response): string
    {
        $message = $response instanceof Throwable ? $response->getMessage() : '';

        return str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false ? 'timeout' : 'connect';
    }

    /**
     * Readable text for a stream_error / alert detail code.
     */
    public static function describeError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        if (str_starts_with($error, 'http:')) {
            $code = (int) substr($error, 5);

            return match (true) {
                in_array($code, [401, 403, 451], true) => __('Refused or blocked in this country (HTTP :code)', ['code' => $code]),
                in_array($code, [404, 410], true) => __('Stream not found (HTTP :code)', ['code' => $code]),
                $code >= 500 => __('Server error (HTTP :code)', ['code' => $code]),
                default => __('Unexpected answer (HTTP :code)', ['code' => $code]),
            };
        }

        return match ($error) {
            'timeout' => __('No answer in time'),
            'connect' => __('Could not connect'),
            'html' => __('A web page instead of a stream'),
            'empty' => __('Empty answer'),
            'no-video' => __('The playlist answers, but the video in it does not'),
            'frozen' => __('The picture has stopped: the stream sends no new video'),
            default => $error,
        };
    }
}
