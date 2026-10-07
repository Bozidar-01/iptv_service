<?php

namespace App\Services;

use App\Jobs\CheckStreams;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamAlert;
use App\Support\CappedStream;
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
 * Backups: when a stream is down but another working channel shows the same programme (the
 * admin's chosen backup_channel_id, or a channel with the same tvg-id), the channel stays on
 * and plays that stream instead (using_backup_id) until its own stream works again.
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
    private const MAX_BYTES = 4096;
    private const USER_AGENT = 'VLC/3.0.20 LibVLC/3.0.20';

    /** Cache counter of queued check jobs not finished yet (see queueCheck). */
    public const PENDING_KEY = 'stream-check:pending';

    /**
     * Channels the check looks at: enabled ones, and ones it switched off itself, except
     * channels of sources with check_streams turned off, and receiver channels (checking them
     * would tune the receiver; ReceiverService checks the relay instead).
     */
    public function checkableQuery(?int $sourceId = null, bool $watchedOnly = false): Builder
    {
        return Channel::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('auto_disabled', true))
            ->where(fn ($q) => $q->whereNull('source_id')
                ->orWhereIn('source_id', Source::select('id')->where('check_streams', true)->where('type', '!=', 'receiver')))
            ->when($sourceId, fn ($q) => $q->where('source_id', $sourceId))
            ->when($watchedOnly, fn ($q) => $q->watched());
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
        if (Cache::decrement(self::PENDING_KEY) <= 0) {
            Cache::forget(self::PENDING_KEY);
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
            ->get(['id', 'name', 'source_id', 'stream_url', 'http_user_agent', 'http_referrer', 'stream_status', 'alert_on_down', 'tvg_id', 'backup_channel_id']);

        $errors = $this->probe($channels);

        // A single timeout or hiccup shouldn't switch a channel off: try the failed ones once more
        $failed = $channels->filter(fn ($channel) => $errors[$channel->id] !== null);
        if ($failed->isNotEmpty()) {
            Sleep::for(self::RETRY_DELAY)->seconds();
            $errors = array_replace($errors, $this->probe($failed));
        }

        $this->recordAlerts($channels, $errors);

        $online = array_keys(array_filter($errors, fn ($error) => $error === null));
        $offline = array_keys(array_filter($errors, fn ($error) => $error !== null));
        $backups = $this->findBackups($channels->whereIn('id', $offline), $online);
        $withoutBackup = array_values(array_diff($offline, array_keys($backups)));
        $now = now();

        // Only flip channels the check owns: switch off enabled dead ones without a backup,
        // switch back on recovered ones and ones that now have a backup
        Channel::whereIn('id', $withoutBackup)->where('is_active', true)->update(['is_active' => false, 'auto_disabled' => true]);
        Channel::whereIn('id', [...$online, ...array_keys($backups)])->where('auto_disabled', true)->update(['is_active' => true, 'auto_disabled' => false]);
        Channel::whereIn('id', $online)->update(['stream_status' => self::STATUS_ONLINE, 'stream_error' => null, 'stream_checked_at' => $now, 'using_backup_id' => null]);
        Channel::whereIn('id', $withoutBackup)->update(['using_backup_id' => null]);
        $this->setBackups($backups);

        // One update per distinct reason (a handful), not per channel
        collect($errors)->reject(fn ($error) => $error === null)
            ->groupBy(fn ($error) => $error, preserveKeys: true)
            ->each(fn ($group, $error) => Channel::whereIn('id', $group->keys())
                ->update(['stream_status' => self::STATUS_OFFLINE, 'stream_error' => $error, 'stream_checked_at' => $now]));

        return ['online' => count($online), 'offline' => count($offline)];
    }

    /**
     * A working channel to play instead of each dead one, if there is one: the admin's chosen
     * backup first, then another channel with the same tvg-id. A backup must be enabled, not
     * itself playing a backup, and working (in this check, or in its last check).
     *
     * @param  Collection<int, Channel>  $dead
     * @param  array<int>  $workingNow  ids found working in this check
     * @return array<int, int> dead channel id => backup channel id
     */
    private function findBackups(Collection $dead, array $workingNow): array
    {
        if ($dead->isEmpty()) {
            return [];
        }

        $deadIds = $dead->pluck('id')->all();
        $tvgIds = $dead->pluck('tvg_id')->filter()->unique()->values()->all();
        $chosenIds = $dead->pluck('backup_channel_id')->filter()->unique()->values()->all();

        if ($tvgIds === [] && $chosenIds === []) {
            return [];
        }

        $candidates = Channel::query()
            ->where(fn ($q) => $q->whereIn('id', $chosenIds)->orWhereIn('tvg_id', $tvgIds))
            ->whereNotIn('id', $deadIds)
            ->where('is_active', true)
            ->whereNull('using_backup_id')
            ->where(fn ($q) => $q->where('stream_status', self::STATUS_ONLINE)->orWhereIn('id', $workingNow))
            ->orderBy('id')
            ->get(['id', 'tvg_id']);

        $byId = $candidates->keyBy('id');
        $byTvg = $candidates->filter(fn ($c) => $c->tvg_id)->groupBy(fn ($c) => mb_strtolower($c->tvg_id));

        $backups = [];
        foreach ($dead as $channel) {
            $backup = ($channel->backup_channel_id ? $byId->get($channel->backup_channel_id) : null)
                ?? ($channel->tvg_id ? $byTvg->get(mb_strtolower($channel->tvg_id))?->first() : null);

            if ($backup) {
                $backups[$channel->id] = $backup->id;
            }
        }

        return $backups;
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
                    ])
                    ->get($channel->stream_url);
            }
        }, self::CONCURRENCY);

        $errors = [];

        foreach ($channels as $channel) {
            $id = $channel->id;
            $response = $responses[(string) $id] ?? null;

            $errors[$id] = match (true) {
                $response instanceof Response => $this->diagnose($response->status(), $response->header('Content-Type'), $response->body()),
                // Aborted on purpose once the sink was full (or timed out mid-stream): judge what arrived
                isset($heads[$id]) => $this->diagnose($heads[$id][0], $heads[$id][1], $sinks[$id]->contents()),
                default => $this->connectionError($response),
            };
        }

        return $errors;
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
            default => $error,
        };
    }
}
