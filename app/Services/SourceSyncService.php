<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Imports a source's M3U playlist into the channels table.
 *
 * Channels are matched across syncs by (source_id, external_key). The key comes from the
 * entry's tvg-id when it has one (so renaming a channel or moving it to another group keeps
 * the same channel, its bouquets and viewers' favourites), otherwise from group + name.
 * Channels imported before tvg-id keys existed are moved to their new key on the next sync. Re-syncing updates name/URL/logo/category/order but keeps
 * the admin's is_active choice; channels that disappeared from the playlist are deleted.
 */
class SourceSyncService
{
    private const CHUNK_SIZE = 500;

    /**
     * Some providers reject unknown clients, so identify as a common player.
     */
    private const USER_AGENT = 'VLC/3.0.20 LibVLC/3.0.20';

    /** @var array<string, int> lowercase category name => id */
    private array $categoryIds = [];

    public function __construct(private M3uParser $parser)
    {
    }

    public function sync(Source $source): void
    {
        $source->update(['status' => Source::STATUS_SYNCING, 'last_error' => null]);

        $tempFile = null;

        try {
            $path = $source->type === 'file'
                ? Storage::path($source->file_path)
                : $tempFile = $this->download($source->playlistUrl());

            $count = $this->import($source, $path);

            $source->update([
                'status' => Source::STATUS_SUCCESS,
                'channel_count' => $count,
                'last_synced_at' => now(),
            ]);
        } catch (Throwable $e) {
            $source->update([
                'status' => Source::STATUS_FAILED,
                // Never store a relay secret that a connection error message may contain
                'last_error' => mb_substr($source->relay_secret ? str_replace([$source->relay_secret, urlencode($source->relay_secret)], '***', $e->getMessage()) : $e->getMessage(), 0, 1000),
            ]);

            if ($source->alert_on_down) {
                StreamAlert::create(['type' => StreamAlert::TYPE_SYNC_FAILED, 'source_id' => $source->id, 'name' => $source->name]);
            }

            throw $e;
        } finally {
            if ($tempFile !== null) {
                @unlink($tempFile);
            }
        }
    }

    private function download(?string $url): string
    {
        if (!$url) {
            throw new RuntimeException('Source has no URL.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'm3u');

        $response = Http::withUserAgent(self::USER_AGENT)
            ->timeout(300)
            ->sink($tempFile)
            ->get($url);

        if ($response->failed()) {
            @unlink($tempFile);
            throw new RuntimeException("Download failed with HTTP {$response->status()}.");
        }

        return $tempFile;
    }

    /**
     * @return int number of channels now belonging to the source
     */
    private function import(Source $source, string $path): int
    {
        $syncedAt = now()->startOfSecond();
        $this->categoryIds = Category::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])
            ->all();

        $rows = [];
        $legacyKeys = [];
        $seenKeys = [];
        $seenLegacy = [];
        $order = 0;
        $sawEntries = false;

        foreach ($this->parser->parseFile($path) as $entry) {
            $sawEntries = true;

            if ($source->skip_vod && $this->parser->isVod($entry['url'])) {
                continue;
            }

            [$key, $legacyKey] = $this->externalKeys($entry, $seenKeys, $seenLegacy);
            if ($key !== $legacyKey) {
                $legacyKeys[$key] = $legacyKey;
            }

            $rows[] = [
                'source_id' => $source->id,
                'external_key' => $key,
                'name' => mb_substr($entry['name'], 0, 255),
                'stream_url' => mb_substr($entry['url'], 0, 2048),
                'logo_url' => $entry['logo'] ? mb_substr($entry['logo'], 0, 2048) : null,
                'tvg_id' => $entry['tvg_id'] ? mb_substr($entry['tvg_id'], 0, 255) : null,
                'http_user_agent' => $entry['user_agent'] ? mb_substr($entry['user_agent'], 0, 512) : null,
                'http_referrer' => $entry['referrer'] ? mb_substr($entry['referrer'], 0, 1024) : null,
                'category_id' => $this->categoryId($entry['group']),
                'sort_order' => ++$order,
                'is_active' => true,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];

            if (count($rows) >= self::CHUNK_SIZE) {
                $this->rekey($source, $legacyKeys);
                $this->upsert($rows);
                $rows = $legacyKeys = [];
            }
        }

        if (!$sawEntries) {
            throw new RuntimeException('The playlist is empty or not a valid M3U file.');
        }

        $this->rekey($source, $legacyKeys);
        $this->upsert($rows);

        // Anything not touched by this sync is no longer in the playlist
        Channel::where('source_id', $source->id)->where('updated_at', '<', $syncedAt)->delete();

        if ($source->bouquet_id) {
            $this->attachToBouquet($source);
        }

        return Channel::where('source_id', $source->id)->count();
    }

    private function upsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // is_active is deliberately not updated so admin enable/disable choices survive re-syncs
        Channel::upsert(
            $rows,
            ['source_id', 'external_key'],
            ['name', 'stream_url', 'logo_url', 'tvg_id', 'http_user_agent', 'http_referrer', 'category_id', 'sort_order', 'updated_at']
        );
    }

    /**
     * Identity for an entry: its tvg-id when present, else group + name; with a counter for
     * duplicates within one playlist. Also returns the group + name key used before tvg-id keys.
     *
     * @return array{0: string, 1: string} [key, legacy key]
     */
    private function externalKeys(array $entry, array &$seenKeys, array &$seenLegacy): array
    {
        $legacyBase = sha1(mb_strtolower(($entry['group'] ?? '').'|'.$entry['name']));
        $seenLegacy[$legacyBase] = ($seenLegacy[$legacyBase] ?? 0) + 1;
        $legacy = $seenLegacy[$legacyBase] === 1 ? $legacyBase : $legacyBase.'-'.$seenLegacy[$legacyBase];

        if (!$entry['tvg_id']) {
            return [$legacy, $legacy];
        }

        $base = 'tvg:'.sha1(mb_strtolower(trim($entry['tvg_id'])));
        $seenKeys[$base] = ($seenKeys[$base] ?? 0) + 1;

        return [$seenKeys[$base] === 1 ? $base : $base.'-'.$seenKeys[$base], $legacy];
    }

    /**
     * Move channels still stored under their old group + name key to their tvg-id key, so the
     * upsert updates them instead of creating new channels. One query per chunk.
     *
     * @param  array<string, string>  $legacyKeys  new key => legacy key
     */
    private function rekey(Source $source, array $legacyKeys): void
    {
        if ($legacyKeys === []) {
            return;
        }

        $existing = Channel::where('source_id', $source->id)
            ->whereIn('external_key', array_merge(array_values($legacyKeys), array_keys($legacyKeys)))
            ->pluck('external_key')
            ->flip();

        // Only legacy rows whose new key isn't taken yet
        $moves = array_filter($legacyKeys, fn ($legacy, $key) => isset($existing[$legacy]) && !isset($existing[$key]), ARRAY_FILTER_USE_BOTH);

        if ($moves === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($moves), 'WHEN ? THEN ?'));
        $placeholders = implode(',', array_fill(0, count($moves), '?'));
        $bindings = [];
        foreach ($moves as $key => $legacy) {
            array_push($bindings, $legacy, $key);
        }

        DB::update(
            "UPDATE channels SET external_key = CASE external_key {$cases} END WHERE source_id = ? AND external_key IN ({$placeholders})",
            [...$bindings, $source->id, ...array_values($moves)]
        );
    }

    private function categoryId(?string $group): ?int
    {
        if ($group === null || trim($group) === '') {
            return null;
        }

        $group = mb_substr(trim($group), 0, 255);
        $lookup = mb_strtolower($group);

        return $this->categoryIds[$lookup] ??= Category::create(['name' => $group])->id;
    }

    private function attachToBouquet(Source $source): void
    {
        ChannelService::attachToBouquet($source->bouquet_id, Channel::where('source_id', $source->id));
    }
}
