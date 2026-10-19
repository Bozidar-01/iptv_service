<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Source;
use App\Support\Countries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Logos and guide ids for channels whose playlist has none, from the iptv-org channel database (public domain,
 * https://github.com/iptv-org/database): about 30,000 TV channels with their names, other names, country and logo.
 *
 * refresh() downloads the database (about 14 MB, weekly) and keeps a small index of it on disk. fill() then matches channels
 * that have no logo or no tvg-id: by the tvg-id when the channel has an iptv-org one, else by name (EpgService::nameKey, so
 * "MK - Kanal 5 HD" is "kanal5"). A name used by channels in several countries only counts when the channel's own country (from
 * its tvg-id, category, name tag or source) picks one. What is found goes in `auto_logo_url` / `auto_tvg_id`, which imports
 * don't overwrite; the source's own logo and tvg-id always win. The guide id then helps the TV guide match (EpgService).
 */
class ChannelCatalogService
{
    public const CHANNELS_URL = 'https://iptv-org.github.io/api/channels.json';

    public const LOGOS_URL = 'https://iptv-org.github.io/api/logos.json';

    /** Days before the database is downloaded again. */
    public const REFRESH_DAYS = 7;

    private const STATUS_KEY = 'channel_catalog';

    /** @var array{names: array<string, list<array{0: string, 1: ?string}>>, logos: array<string, string>, ids: array<string, true>}|null */
    private ?array $index = null;

    public function indexPath(): string
    {
        return storage_path('app/channel-catalog.json');
    }

    /** @return array{fetched_at: ?int, channels: int, logos: int, filled_logos: int, filled_ids: int, error: ?string} */
    public function status(): array
    {
        return array_merge(['fetched_at' => null, 'channels' => 0, 'logos' => 0, 'filled_logos' => 0, 'filled_ids' => 0, 'error' => null], (array) AppSetting::get(self::STATUS_KEY, []));
    }

    public function isStale(): bool
    {
        $fetched = $this->status()['fetched_at'];

        return !is_file($this->indexPath()) || $fetched === null || $fetched < now()->subDays(self::REFRESH_DAYS)->timestamp;
    }

    /**
     * Download the iptv-org database and save the index. Closed channels are left out.
     */
    public function refresh(): void
    {
        // The database is a few MB of JSON; decoding it needs more memory than a web request has
        @ini_set('memory_limit', '1024M');

        try {
            $channels = $this->download(self::CHANNELS_URL);
            $names = [];
            $ids = [];
            foreach ($channels as $row) {
                if (!is_array($row) || empty($row['id']) || !empty($row['closed'])) {
                    continue;
                }
                $country = isset($row['country']) ? $this->countryCode((string) $row['country']) : null;
                $ids[mb_strtolower($row['id'])] = true;
                foreach (array_unique([(string) ($row['name'] ?? ''), ...array_map('strval', (array) ($row['alt_names'] ?? []))]) as $name) {
                    $key = EpgService::nameKey($name);
                    if ($key !== '') {
                        $names[$key][] = [$row['id'], $country];
                    }
                }
            }
            unset($channels);

            // One logo per channel: its main feed's, in use, the widest one
            $best = [];
            foreach ($this->download(self::LOGOS_URL) as $row) {
                if (!is_array($row) || empty($row['channel']) || empty($row['url']) || ($row['in_use'] ?? true) === false) {
                    continue;
                }
                $id = mb_strtolower($row['channel']);
                $score = (empty($row['feed']) ? 100000 : 0) + (int) ($row['width'] ?? 0);
                if (!isset($best[$id]) || $score > $best[$id][0]) {
                    $best[$id] = [$score, (string) $row['url']];
                }
            }
            $logos = array_map(fn ($b) => $b[1], $best);

            File::ensureDirectoryExists(dirname($this->indexPath()));
            File::put($this->indexPath(), json_encode(['names' => $names, 'logos' => $logos, 'ids' => array_keys($ids)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->index = null;

            AppSetting::put(self::STATUS_KEY, array_merge($this->status(), ['fetched_at' => now()->timestamp, 'channels' => count($ids), 'logos' => count($logos), 'error' => null]));
        } catch (\Throwable $e) {
            AppSetting::put(self::STATUS_KEY, array_merge($this->status(), ['error' => mb_substr($e->getMessage(), 0, 500)]));

            throw $e;
        }
    }

    /**
     * Find logos and guide ids for channels without them (of one source, or all). Needs the index (refresh()); does nothing without
     * it. Set-based: one query per 1000 channels found.
     *
     * @return array{logos: int, ids: int}
     */
    public function fill(?int $sourceId = null): array
    {
        $index = $this->index();
        if ($index === null) {
            return ['logos' => 0, 'ids' => 0];
        }

        $categories = Category::pluck('name', 'id')->all();
        $sources = Source::pluck('name', 'id')->all();
        $found = ['logos' => 0, 'ids' => 0];

        Channel::query()
            ->when($sourceId, fn ($q) => $q->where('source_id', $sourceId))
            ->where(fn ($q) => $q->whereNull('logo_url')->orWhere('logo_url', '')->orWhereNull('tvg_id')->orWhere('tvg_id', ''))
            ->select(['id', 'name', 'tvg_id', 'logo_url', 'category_id', 'source_id', 'auto_logo_url', 'auto_tvg_id'])
            ->chunkById(1000, function ($channels) use ($index, $categories, $sources, &$found) {
                $changes = [];

                foreach ($channels as $channel) {
                    $id = $this->match($channel, $index, $categories[$channel->category_id] ?? null, $sources[$channel->source_id] ?? null);
                    $logo = $id !== null && !$channel->logo_url ? ($index['logos'][mb_strtolower($id)] ?? null) : null;
                    $guide = $id !== null && !$channel->tvg_id ? $id : null;

                    if ($logo !== $channel->auto_logo_url || $guide !== $channel->auto_tvg_id) {
                        $changes[$channel->id] = [$logo, $guide];
                    }
                    $found['logos'] += $logo !== null ? 1 : 0;
                    $found['ids'] += $guide !== null ? 1 : 0;
                }

                $this->save($changes);
            });

        if ($sourceId === null) {
            AppSetting::put(self::STATUS_KEY, array_merge($this->status(), ['filled_logos' => $found['logos'], 'filled_ids' => $found['ids']]));
        }

        return $found;
    }

    /**
     * The iptv-org id of a channel: its own tvg-id when that is one, else the one channel with its name (in its country when
     * several countries have the name).
     *
     * @param  array{names: array<string, list<array{0: string, 1: ?string}>>, logos: array<string, string>, ids: array<string, true>}  $index
     */
    private function match(Channel $channel, array $index, ?string $category, ?string $source): ?string
    {
        if ($channel->tvg_id) {
            $own = preg_replace('/@[^@]*$/', '', $channel->tvg_id);
            if (isset($index['ids'][mb_strtolower($own)])) {
                return $own;
            }
        }

        $candidates = $index['names'][EpgService::nameKey($channel->name)] ?? [];
        if ($candidates === []) {
            return null;
        }

        $byId = collect($candidates)->unique(fn ($c) => mb_strtolower($c[0]));
        if ($byId->count() === 1) {
            return $byId->first()[0];
        }

        $country = Countries::fromGuideId($channel->tvg_id)
            ?? ($category !== null ? Countries::fromText(trim(explode(' – ', $category)[0])) : null)
            ?? Countries::fromNameTag($channel->name)
            ?? ($source !== null ? Countries::fromText($source) : null);
        if ($country === null) {
            return null;
        }

        $inCountry = $byId->filter(fn ($c) => $c[1] === $country);

        return $inCountry->count() === 1 ? $inCountry->first()[0] : null;
    }

    /** @return array{names: array<string, list<array{0: string, 1: ?string}>>, logos: array<string, string>, ids: array<string, true>}|null */
    private function index(): ?array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        if (!is_file($this->indexPath())) {
            return null;
        }

        $data = json_decode((string) File::get($this->indexPath()), true);
        if (!is_array($data)) {
            return null;
        }

        return $this->index = [
            'names' => (array) ($data['names'] ?? []),
            'logos' => array_change_key_case((array) ($data['logos'] ?? [])),
            'ids' => array_fill_keys(array_map('mb_strtolower', (array) ($data['ids'] ?? [])), true),
        ];
    }

    /** @return array<int, mixed> */
    private function download(string $url): array
    {
        $response = Http::timeout(120)->connectTimeout(15)->get($url);
        if ($response->failed()) {
            throw new RuntimeException("Download of {$url} failed with HTTP {$response->status()}.");
        }

        $data = json_decode($response->body(), true);
        if (!is_array($data)) {
            throw new RuntimeException("{$url} is not a JSON list.");
        }

        return $data;
    }

    /** iptv-org writes country codes in capitals and the United Kingdom as UK; the guide and country lists use "gb". */
    private function countryCode(string $code): string
    {
        $code = strtolower($code);

        return $code === 'uk' ? 'gb' : $code;
    }

    /**
     * @param  array<int, array{0: ?string, 1: ?string}>  $changes  channel id => [auto logo, auto guide id]
     */
    private function save(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $logoCases = $idCases = '';
        $logoBindings = $idBindings = [];
        foreach ($changes as $id => [$logo, $guide]) {
            $logoCases .= ' WHEN ? THEN ?';
            array_push($logoBindings, $id, $logo);
            $idCases .= ' WHEN ? THEN ?';
            array_push($idBindings, $id, $guide);
        }
        $marks = implode(',', array_fill(0, count($changes), '?'));

        DB::update(
            "UPDATE channels SET auto_logo_url = CASE id{$logoCases} END, auto_tvg_id = CASE id{$idCases} END, updated_at = ? WHERE id IN ({$marks})",
            [...$logoBindings, ...$idBindings, now(), ...array_keys($changes)]
        );
    }
}
