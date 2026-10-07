<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
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
 *     become "kanal5").
 * When several guides have the channel, the oldest guide source wins, so results are stable.
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
     * Match channels to the guides. Set-based: one query per 1000 changed channels.
     *
     * @return int channels that have a guide afterwards
     */
    public function matchChannels(?int $sourceId = null): int
    {
        // Guide channel lookups, oldest guide source first so it wins
        $byId = [];
        $byName = [];
        EpgChannel::orderBy('epg_source_id')->orderBy('id')
            ->select(['epg_source_id', 'xmltv_id', 'name_key'])
            ->lazy(5000)
            ->each(function ($row) use (&$byId, &$byName) {
                $byId[mb_strtolower($row->xmltv_id)] ??= [$row->epg_source_id, $row->xmltv_id];
                if ($row->name_key !== '') {
                    $byName[$row->name_key] ??= [$row->epg_source_id, $row->xmltv_id];
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
                            ?? ($byName[self::nameKey($channel->name)] ?? null)
                            ?? [null, null];
                    }

                    if ($match[0] !== $channel->epg_source_id || $match[1] !== $channel->epg_id) {
                        $changes[$channel->id] = $match;
                    }
                }

                $this->saveMatches($changes);
            });

        return Channel::whereNotNull('epg_source_id')->count();
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
