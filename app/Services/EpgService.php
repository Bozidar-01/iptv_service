<?php

namespace App\Services;

use App\Jobs\SyncEpgSource;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\EpgSource;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TV guide: matches our channels to guide channels (channels.epg_source_id + epg_id) and serves
 * the guide to TV apps (XMLTV for playlists, short EPG for the Xtream API).
 *
 * Matching, for channels the admin hasn't set by hand (epg_manual):
 *  1. the channel's tvg-id equals a guide channel id (also without iptv-org's "@SD"/"@HD" suffix);
 *  2. otherwise the normalised names are equal (nameKey: "Канал 5 HD" and "MK - Kanal 5" both
 *     become "kanal5"), also against the guide channel's other display names.
 * When several guides have the channel, the guide of the channel's own country wins (from a tvg-id like
 * "Name.es@SD"), else the oldest guide source, so results are stable.
 */
class EpgService
{
    private const CHUNK_SIZE = 1000;

    /**
     * Comparable form of a channel name: no country prefix ("MK - "), quality tags, brackets
     * or punctuation; Cyrillic transliterated; lower case.
     */
    public static function nameKey(string $name): string
    {
        $name = preg_replace('/^\s*[A-Za-z]{2,3}\s*[-|:]\s+/u', '', $name);   // "MK - Kanal 5", "IT| Rai 1"
        $name = preg_replace('/\[[^\]]*\]|\([^)]*\)/u', ' ', $name);          // "(1080p)", "[Geo-blocked]"
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/\b(hd|fhd|uhd|4k|sd|hevc|h265|backup)\b/', ' ', $name);

        return preg_replace('/[^a-z0-9+]+/', '', $name);
    }

    /**
     * The country in a playlist id such as "CanalShowsport.ar@SD" (iptv-org style), matching the guide files' codes
     * (iptv-org says "uk" where the guide files say "gb"). Null when the id has none.
     */
    public static function countryOf(?string $tvgId): ?string
    {
        if (!$tvgId || !preg_match('/\.([a-z]{2})(?:@[^@]*)?$/i', $tvgId, $m)) {
            return null;
        }

        $code = strtolower($m[1]);

        return $code === 'uk' ? 'gb' : $code;
    }

    /**
     * What the channels we have can match in a guide: their guide ids (also without "@SD" / "@HD") and their name keys.
     * The importer keeps programmes only for guide channels that match one of these, so a huge country file doesn't fill
     * the database with channels nobody has. Null when there are no channels yet (then everything is kept).
     *
     * @return array{ids: array<string, true>, names: array<string, true>}|null
     */
    public function wantedLookups(): ?array
    {
        $ids = $names = [];

        Channel::query()->where('is_active', true)->select(['id', 'name', 'tvg_id', 'epg_id', 'epg_manual'])->chunkById(5000, function ($channels) use (&$ids, &$names) {
            foreach ($channels as $channel) {
                $tvg = mb_strtolower((string) $channel->tvg_id);
                if ($tvg !== '') {
                    $ids[$tvg] = true;
                    $ids[preg_replace('/@[^@]*$/', '', $tvg)] = true;
                }
                if ($channel->epg_manual && $channel->epg_id) {
                    $ids[mb_strtolower($channel->epg_id)] = true;
                }
                $key = self::nameKey($channel->name);
                if ($key !== '') {
                    $names[$key] = true;
                }
            }
        });

        return $ids === [] && $names === [] ? null : ['ids' => $ids, 'names' => $names];
    }

    /**
     * Match channels to the guides. Set-based: one query per 1000 changed channels.
     *
     * @return int channels that have a guide afterwards
     */
    public function matchChannels(?int $sourceId = null): int
    {
        // Guide channel lookups, oldest guide source first so it wins; each guide's country for the name matches
        $countries = EpgSource::get(['id', 'url'])->mapWithKeys(fn (EpgSource $s) => [$s->id => $s->country()])->all();
        $byId = [];
        $byName = [];
        EpgChannel::orderBy('epg_source_id')->orderBy('id')
            ->select(['epg_source_id', 'xmltv_id', 'name_key', 'alt_keys'])
            ->lazy(5000)
            ->each(function ($row) use (&$byId, &$byName, $countries) {
                $byId[mb_strtolower($row->xmltv_id)] ??= [$row->epg_source_id, $row->xmltv_id];
                $keys = array_filter(array_unique([$row->name_key, ...explode('|', (string) $row->alt_keys)]));
                foreach ($keys as $key) {
                    $byName[$key][] = [$row->epg_source_id, $row->xmltv_id, $countries[$row->epg_source_id] ?? null];
                }
            });

        Channel::query()
            ->when($sourceId, fn ($q) => $q->where('source_id', $sourceId))
            ->select(['id', 'name', 'tvg_id', 'epg_source_id', 'epg_id', 'epg_manual'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $channels) use ($byId, $byName) {
                $changes = [];

                foreach ($channels as $channel) {
                    if ($channel->epg_manual) {
                        // Keep the admin's guide ID; only refresh which guide source has it
                        $match = $channel->epg_id ? ($byId[mb_strtolower($channel->epg_id)] ?? null) : null;
                        $match = $match ? [$match[0], $channel->epg_id] : [null, $channel->epg_id];
                    } else {
                        $tvg = mb_strtolower((string) $channel->tvg_id);
                        $match = ($tvg !== '' ? ($byId[$tvg] ?? $byId[preg_replace('/@[^@]*$/', '', $tvg)] ?? null) : null)
                            ?? $this->byName($byName[self::nameKey($channel->name)] ?? [], self::countryOf($channel->tvg_id))
                            ?? [null, null];
                    }

                    if ($match[0] !== $channel->epg_source_id || $match[1] !== $channel->epg_id) {
                        $changes[$channel->id] = [$match[0], $match[1]];
                    }
                }

                $this->saveMatches($changes);
            });

        return Channel::whereNotNull('epg_source_id')->count();
    }

    /**
     * Guides that were imported before some channels of ours were there have dropped those channels' programmes (the importer
     * keeps only what our channels can use). After new channels are imported and matched, queue an import of the guides that now
     * have matched channels without programmes, at most one import per guide every 6 hours.
     *
     * @return int imports queued
     */
    public function refreshGuidesMissingProgrammes(): int
    {
        $ids = Channel::where('is_active', true)->whereNotNull('epg_source_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('epg_programmes')
                ->whereColumn('epg_programmes.epg_source_id', 'channels.epg_source_id')
                ->whereColumn('epg_programmes.xmltv_id', 'channels.epg_id')
                ->where('epg_programmes.stop', '>', now()))
            ->distinct()->pluck('epg_source_id');

        $queued = 0;
        foreach (EpgSource::whereIn('id', $ids)->where('status', Source::STATUS_SUCCESS)
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subHours(6)))->get() as $guide) {
            $guide->update(['status' => Source::STATUS_QUEUED]);
            SyncEpgSource::dispatch($guide);
            $queued++;
        }

        return $queued;
    }

    /**
     * Of the guide channels with this name, the one from the channel's own country when it has one, else the first
     * (the guide added first).
     *
     * @param  list<array{0: int, 1: string, 2: ?string}>  $candidates
     * @return array{0: int, 1: string}|null
     */
    private function byName(array $candidates, ?string $country): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $pick = ($country ? collect($candidates)->first(fn ($c) => $c[2] === $country) : null) ?? $candidates[0];

        return [$pick[0], $pick[1]];
    }

    /**
     * @param  array<int, array{0: ?int, 1: ?string}>  $changes  channel id => [epg source id, epg id]
     */
    private function saveMatches(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $sourceCases = $idCases = '';
        $sourceBindings = $idBindings = [];
        foreach ($changes as $id => [$epgSourceId, $epgId]) {
            $sourceCases .= ' WHEN ? THEN ?';
            array_push($sourceBindings, $id, $epgSourceId);
            $idCases .= ' WHEN ? THEN ?';
            array_push($idBindings, $id, $epgId);
        }
        $placeholders = implode(',', array_fill(0, count($changes), '?'));

        DB::update(
            "UPDATE channels SET epg_source_id = CASE id{$sourceCases} END, epg_id = CASE id{$idCases} END WHERE id IN ({$placeholders})",
            [...$sourceBindings, ...$idBindings, ...array_keys($changes)]
        );
    }

    /**
     * Programmes of one channel from $from on, in time order.
     */
    public function programmes(Channel $channel, ?\DateTimeInterface $from = null, ?int $limit = null): Collection
    {
        if (!$channel->epg_source_id || !$channel->epg_id) {
            return collect();
        }

        return EpgProgramme::where('epg_source_id', $channel->epg_source_id)
            ->where('xmltv_id', $channel->epg_id)
            ->where('stop', '>', $from ?? now())
            ->orderBy('start')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    public function nowPlaying(Channel $channel): ?EpgProgramme
    {
        return $this->programmes($channel, now(), 1)->first(fn ($p) => $p->start->lte(now()));
    }

    /**
     * XMLTV guide for the channels a user can watch: from 2 hours ago up to iptv.epg_days ahead.
     * Written as it is read, since it can hold tens of thousands of programmes.
     */
    public function writeXmltv(User $user, callable $write): void
    {
        $guides = Channel::availableTo($user)
            ->whereNotNull('epg_source_id')
            ->select(['epg_source_id', 'epg_id'])
            ->distinct()
            ->get()
            ->groupBy('epg_source_id')
            ->map(fn ($rows) => $rows->pluck('epg_id')->all());

        $write("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv generator-info-name=\"".e(config('app.name'))."\">\n");

        $names = EpgChannel::where(function ($q) use ($guides) {
            foreach ($guides as $sourceId => $ids) {
                $q->orWhere(fn ($q) => $q->where('epg_source_id', $sourceId)->whereIn('xmltv_id', $ids));
            }
        })->when($guides->isEmpty(), fn ($q) => $q->whereRaw('1 = 0'))->pluck('name', 'xmltv_id');

        foreach ($guides->flatten()->unique() as $id) {
            $write('  <channel id="'.e($id).'"><display-name>'.e($names[$id] ?? $id)."</display-name></channel>\n");
        }

        foreach ($guides as $sourceId => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                EpgProgramme::where('epg_source_id', $sourceId)
                    ->whereIn('xmltv_id', $chunk)
                    ->where('stop', '>', now()->subHours(2))
                    ->where('start', '<', now()->addDays((int) config('iptv.epg_days')))
                    ->orderBy('id')
                    ->lazyById(2000)
                    ->each(function ($p) use ($write) {
                        $write(sprintf(
                            "  <programme start=\"%s +0000\" stop=\"%s +0000\" channel=\"%s\"><title>%s</title>%s</programme>\n",
                            $p->start->format('YmdHis'),
                            $p->stop->format('YmdHis'),
                            e($p->xmltv_id),
                            e($p->title),
                            $p->description ? '<desc>'.e($p->description).'</desc>' : ''
                        ));
                    });
            }
        }

        $write("</tv>\n");
    }

    /**
     * Xtream Codes EPG listings for a channel (get_short_epg / get_simple_data_table).
     */
    public function xtreamListings(Channel $channel, ?int $limit, bool $withNowPlaying): array
    {
        $now = now();

        return $this->programmes($channel, $limit ? $now : $now->copy()->startOfDay(), $limit)
            ->map(function ($p) use ($channel, $now, $withNowPlaying) {
                $row = [
                    'id' => (string) $p->id,
                    'epg_id' => (string) $p->id,
                    'title' => base64_encode($p->title),
                    'lang' => '',
                    'start' => $p->start->format('Y-m-d H:i:s'),
                    'end' => $p->stop->format('Y-m-d H:i:s'),
                    'description' => base64_encode((string) $p->description),
                    'channel_id' => $channel->guideId(),
                    'start_timestamp' => (string) $p->start->timestamp,
                    'stop_timestamp' => (string) $p->stop->timestamp,
                    'stream_id' => (string) $channel->id,
                ];

                if ($withNowPlaying) {
                    $row['now_playing'] = $p->start->lte($now) && $p->stop->gt($now) ? 1 : 0;
                    $row['has_archive'] = 0;
                }

                return $row;
            })
            ->values()
            ->all();
    }
}
