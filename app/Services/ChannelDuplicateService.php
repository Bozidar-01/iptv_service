<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Finds the same channel listed more than once (several sources, or one source twice), says which copy is best
 * and switches the others off. Nothing is deleted: a disabled channel stays disabled through every sync, while a
 * deleted one would be imported again.
 *
 * Two enabled channels are the same channel when their names match once case, country prefixes ("IT:", "|IT|"),
 * quality tags (HD, FHD, 4K…) and punctuation are ignored, or when they share a guide (tvg) id. Groups that have
 * no channel in any bouquet are left out (nobody gets them), and so are groups too big to be one channel.
 *
 * The suggested copy is the one that works, then not slow, then the most reliable over the last week (in steps of 10%), then the best quality (the picture size the stream check measured in an HLS playlist, else the tag in the name, then the bitrate), then not a satellite receiver (one tuner
 * serves only a few streams), then the one in most bouquets, then the most recently checked.
 */
class ChannelDuplicateService
{
    /** More copies than this is not one channel: a generic guide id or name is joining unrelated ones. */
    public const MAX_GROUP_SIZE = 12;

    /** Guide ids that mean nothing. */
    private const EMPTY_GUIDE_IDS = ['', 'none', 'null', 'n/a', 'na', '-', '0', 'unknown'];

    /**
     * What a name boils down to for comparing: "IT: Rai 1 HD" and "Rai 1" are the same, "Sky Sports +1" is not "Sky Sports 1".
     */
    public static function normalize(string $name): string
    {
        $n = mb_strtolower($name);
        $n = str_replace(['+', '&'], [' plus ', ' and '], $n);
        // A leading country code: "it:", "it|", "|it|", "[it]", "(it)"
        $n = preg_replace('/^\s*(?:[\[\(\|]\s*[a-z]{2,3}\s*[\]\)\|]|[a-z]{2,3}\s*[:|])\s*/u', '', $n);
        $n = preg_replace('/\b(?:4k|uhd|fhd|hd|sd|hevc|h\.?26[45]|1080[pi]?|720[pi]?|576[pi]?|480p|50fps|60fps|hq|lq|raw)\b/u', ' ', $n);
        $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n);

        return trim(preg_replace('/\s+/u', ' ', $n));
    }

    /** 5 = 4K, 4 = Full HD, 3 = HD, 2 = no tag, 1 = SD. */
    public static function quality(string $name): int
    {
        $n = mb_strtolower($name);

        return match (true) {
            (bool) preg_match('/\b(?:4k|uhd|2160p?)\b/u', $n) => 5,
            (bool) preg_match('/\b(?:fhd|1080[pi]?)\b/u', $n) => 4,
            (bool) preg_match('/\b(?:hd|720[pi]?)\b/u', $n) => 3,
            (bool) preg_match('/\b(?:sd|576[pi]?|480p|lq)\b/u', $n) => 1,
            default => 2,
        };
    }

    /** The quality score: the measured picture height when the stream check found it, else what the name says. */
    public static function qualityOf(string $name, ?int $height): int
    {
        return match (true) {
            $height === null || $height <= 0 => self::quality($name),
            $height >= 2000 => 5,
            $height >= 1000 => 4,
            $height >= 700 => 3,
            default => 1,
        };
    }

    /**
     * Every group of copies with the suggested one, sorted by name. With $withReceiver only groups that have a satellite
     * receiver channel: keeping a provider copy and the receiver channel as its hidden backup is the receiver's standby (and
     * the other way round).
     *
     * @return list<array{key: string, name: string, suggested: int, channels: list<array<string, mixed>>}>
     */
    public function groups(?string $search = null, bool $withReceiver = false): array
    {
        $components = $this->components();
        if ($components === []) {
            return [];
        }

        $sources = Source::pluck('type', 'id')->all();
        $sourceNames = Source::pluck('name', 'id')->all();

        // Details only for channels that are in a group
        $details = [];
        foreach (array_chunk(array_merge(...$components), 1000) as $ids) {
            foreach (Channel::whereIn('id', $ids)->get(['id', 'name', 'source_id', 'stream_status', 'stream_checked_at', 'quality_height', 'quality_kbps', 'reliability', 'stream_speed']) as $c) {
                $details[$c->id] = $c;
            }
        }
        $bouquets = [];
        foreach (array_chunk(array_keys($details), 1000) as $ids) {
            $bouquets += DB::table('bouquet_channel')->whereIn('channel_id', $ids)->selectRaw('channel_id, count(*) as n')->groupBy('channel_id')->pluck('n', 'channel_id')->all();
        }

        $groups = [];
        foreach ($components as $ids) {
            $members = [];
            foreach ($ids as $id) {
                $c = $details[$id];
                $status = $c->stream_status === StreamCheckService::STATUS_ONLINE ? 2 : ($c->stream_status === StreamCheckService::STATUS_OFFLINE ? 0 : 1);
                $members[] = [
                    'id' => $id,
                    'name' => $c->name,
                    'source' => $c->source_id ? ($sourceNames[$c->source_id] ?? '–') : __('Manual'),
                    'receiver' => ($sources[$c->source_id] ?? null) === 'receiver',
                    'status' => $c->stream_status,
                    'checked_at' => $c->stream_checked_at?->timestamp,
                    'bouquets' => (int) ($bouquets[$id] ?? 0),
                    'quality' => self::qualityOf($c->name, $c->quality_height),
                    'measured' => $c->quality_height ? ['height' => (int) $c->quality_height, 'kbps' => (int) $c->quality_kbps] : null,
                    'reliability' => $c->reliability,
                    'slow' => ChannelFailoverService::isSlow($c->stream_speed),
                    // Higher is better, compared left to right; reliability in steps of 10%, so 92% and 99% count the same
                    'score' => [$status, ChannelFailoverService::isSlow($c->stream_speed) ? 0 : 1, intdiv($c->reliability ?? 50, 10), self::qualityOf($c->name, $c->quality_height), intdiv((int) $c->quality_kbps, 500), ($sources[$c->source_id] ?? null) === 'receiver' ? 0 : 1, (int) ($bouquets[$id] ?? 0), $c->stream_checked_at->timestamp ?? 0, -$id],
                ];
            }

            // Nobody gets the group when none of its copies is in a bouquet
            if (array_sum(array_column($members, 'bouquets')) === 0) {
                continue;
            }

            usort($members, fn ($a, $b) => $b['score'] <=> $a['score']);
            $sortedIds = array_column($members, 'id');
            sort($sortedIds);

            $groups[] = [
                'key' => md5(implode(',', $sortedIds)),
                'name' => $members[0]['name'],
                'suggested' => $members[0]['id'],
                'channels' => $members,
            ];
        }

        usort($groups, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        if ($withReceiver) {
            $groups = array_values(array_filter($groups, fn ($g) => collect($g['channels'])->contains('receiver', true)));
        }

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $groups = array_values(array_filter($groups, fn ($g) => collect($g['channels'])->contains(fn ($c) => str_contains(mb_strtolower($c['name']), $needle))));
        }

        return $groups;
    }

    /**
     * Take every copy except the chosen one out of the lists in each group. The kept channel joins the bouquets of the others, so
     * nobody loses the channel. With $asBackups (the default) the others stay on as hidden backup copies of the kept one
     * (`backup_for_id`): viewers don't see them, the stream check keeps checking them, and they stand in when the kept one stops
     * working (ChannelFailoverService). Without it they are switched off. Groups that changed since they were shown, or a kept
     * channel that is not in its group, are skipped.
     *
     * @param  array<string, int>  $keep  group key => channel id to keep
     * @return array{groups: int, disabled: int, skipped: int}
     */
    public function apply(array $keep, ?string $search = null, bool $asBackups = true): array
    {
        $groups = collect($this->groups($search))->keyBy('key');
        $result = ['groups' => 0, 'disabled' => 0, 'skipped' => 0];

        foreach ($keep as $key => $keepId) {
            $group = $groups->get($key);
            $ids = $group ? array_column($group['channels'], 'id') : [];

            if (!$group || !in_array((int) $keepId, $ids, true)) {
                $result['skipped']++;

                continue;
            }

            $losers = array_values(array_diff($ids, [(int) $keepId]));

            DB::transaction(function () use ($keepId, $losers, $asBackups) {
                // The kept channel goes into every bouquet another copy was in
                DB::table('bouquet_channel')->insertOrIgnore(
                    DB::table('bouquet_channel')->whereIn('channel_id', $losers)->distinct()->pluck('bouquet_id')
                        ->map(fn ($bouquetId) => ['bouquet_id' => $bouquetId, 'channel_id' => (int) $keepId])->all()
                );
                if ($asBackups) {
                    // Backup copies of the others now belong to the kept channel too
                    Channel::whereIn('backup_for_id', $losers)->update(['backup_for_id' => (int) $keepId]);
                    Channel::whereIn('id', $losers)->update(['backup_for_id' => (int) $keepId]);
                } else {
                    Channel::whereIn('id', $losers)->update(['is_active' => false, 'auto_disabled' => false]);
                }
            });

            $result['groups']++;
            $result['disabled'] += count($losers);
        }

        return $result;
    }

    /**
     * Ids of channels that are the same one, as lists of 2 to MAX_GROUP_SIZE. Union by name and by guide id over
     * all enabled channels, read in chunks with only the columns needed.
     *
     * @return list<list<int>>
     */
    private function components(): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent) {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $first = [];

        Channel::where('is_active', true)->whereNull('backup_for_id')->select('id', 'name', 'tvg_id')->chunkById(5000, function ($chunk) use (&$parent, &$first, $find) {
            foreach ($chunk as $c) {
                $parent[$c->id] = $c->id;
                $keys = [];

                $name = self::normalize($c->name);
                if (mb_strlen($name) >= 2) {
                    $keys[] = 'n:'.$name;
                }
                $guide = mb_strtolower(trim((string) $c->tvg_id));
                if (!in_array($guide, self::EMPTY_GUIDE_IDS, true)) {
                    $keys[] = 't:'.$guide;
                }

                foreach ($keys as $key) {
                    if (isset($first[$key])) {
                        $parent[$find($c->id)] = $find($first[$key]);
                    } else {
                        $first[$key] = $c->id;
                    }
                }
            }
        });

        $components = [];
        foreach (array_keys($parent) as $id) {
            $components[$find($id)][] = $id;
        }

        return array_values(array_filter($components, fn ($ids) => count($ids) >= 2 && count($ids) <= self::MAX_GROUP_SIZE));
    }
}
