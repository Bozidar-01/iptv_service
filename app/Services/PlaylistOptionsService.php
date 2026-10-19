<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Channel;
use Illuminate\Support\Facades\DB;

/**
 * Options for what viewers' playlists and apps show (Playlist options page):
 *
 *  - quality_in_names: add the picture quality to channel names ("Rai 1 FHD"), from what the stream check measured, else the tag
 *    in the name. Tags already in the name are replaced, so names don't end up as "Rai 1 HD FHD".
 *  - hide_worse_copies: when the same channel (same tvg-id) is there twice, list only the better one. A copy is "worse" when a
 *    working copy has a better picture and is in every bouquet the worse one is in (so nobody loses the channel); see
 *    refreshBetterCopies(). It still plays, so links saved in apps keep working.
 */
class PlaylistOptionsService
{
    public const KEY = 'playlist_options';

    public const DEFAULTS = ['quality_in_names' => false, 'hide_worse_copies' => false];

    private const TAGS = '/(?<![\p{L}\p{N}])(?:4k|uhd|fhd|full ?hd|hd|sd|hevc|h\.?26[45]|1080[pi]?|720[pi]?|576[pi]?|480p|2160p?)(?![\p{L}\p{N}])/iu';

    /** @var array{quality_in_names: bool, hide_worse_copies: bool}|null */
    private ?array $settings = null;

    /** @return array{quality_in_names: bool, hide_worse_copies: bool} */
    public function settings(): array
    {
        return $this->settings ??= array_map('boolval', array_merge(self::DEFAULTS, array_intersect_key((array) AppSetting::get(self::KEY, []), self::DEFAULTS)));
    }

    /** @param  array{quality_in_names?: bool, hide_worse_copies?: bool}  $values */
    public function save(array $values): void
    {
        $settings = array_map('boolval', array_merge($this->settings(), array_intersect_key($values, self::DEFAULTS)));
        AppSetting::put(self::KEY, $settings);
        $this->settings = $settings;

        if ($settings['hide_worse_copies']) {
            $this->refreshBetterCopies();
        }
        ListCacheService::bump();
    }

    /**
     * The quality label of a channel: measured picture height, else the tag in its name. Null when nothing tells.
     */
    public static function label(Channel $channel): ?string
    {
        return match (ChannelDuplicateService::qualityOf($channel->name, $channel->quality_height)) {
            5 => '4K',
            4 => 'FHD',
            3 => 'HD',
            1 => 'SD',
            default => null,
        };
    }

    /** The name viewers see. */
    public function displayName(Channel $channel): string
    {
        if (!$this->settings()['quality_in_names'] || ($label = self::label($channel)) === null) {
            return $channel->name;
        }

        $base = trim(preg_replace('/\s{2,}/u', ' ', preg_replace(self::TAGS, ' ', $channel->name)), " \t-|:");

        return ($base !== '' ? $base : $channel->name).' '.$label;
    }

    /**
     * Work out which channels have a better copy (`channels.better_copy_id`), for hide_worse_copies. Channels with the same tvg-id
     * are copies; the best one is the working copy with the best picture (then the most reliable, then the lowest id). Another copy
     * gets it as its better copy when the best one has a strictly better picture and is in all of its bouquets.
     *
     * @return int channels that have a better copy
     */
    public function refreshBetterCopies(): int
    {
        DB::table('channels')->whereNotNull('better_copy_id')->update(['better_copy_id' => null]);

        $keys = DB::table('channels')->where('is_active', true)->whereNotNull('tvg_id')->where('tvg_id', '!=', '')
            ->selectRaw('LOWER(tvg_id) as k')->groupByRaw('LOWER(tvg_id)')->havingRaw('COUNT(*) > 1')->pluck('k')->all();

        $better = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $channels = Channel::query()->where('is_active', true)
                ->whereIn(DB::raw('LOWER(tvg_id)'), $chunk)
                ->get(['id', 'name', 'tvg_id', 'stream_status', 'quality_height', 'reliability']);
            $bouquets = DB::table('bouquet_channel')->whereIn('channel_id', $channels->pluck('id'))->get()
                ->groupBy('channel_id')->map(fn ($rows) => $rows->pluck('bouquet_id')->all());

            foreach ($channels->groupBy(fn (Channel $c) => mb_strtolower($c->tvg_id)) as $copies) {
                $best = $copies->where('stream_status', StreamCheckService::STATUS_ONLINE)
                    ->sortBy(fn (Channel $c) => [-ChannelDuplicateService::qualityOf($c->name, $c->quality_height), -($c->reliability ?? 50), $c->id])
                    ->first();
                if (!$best) {
                    continue;
                }

                $bestScore = ChannelDuplicateService::qualityOf($best->name, $best->quality_height);
                $bestBouquets = $bouquets[$best->id] ?? [];

                foreach ($copies as $copy) {
                    $own = $bouquets[$copy->id] ?? [];
                    if ($copy->id !== $best->id && $own !== [] && $bestScore > ChannelDuplicateService::qualityOf($copy->name, $copy->quality_height)
                        && array_diff($own, $bestBouquets) === []) {
                        $better[$copy->id] = $best->id;
                    }
                }
            }
        }

        foreach (array_chunk($better, 1000, true) as $chunk) {
            $cases = implode(' ', array_fill(0, count($chunk), 'WHEN ? THEN ?'));
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $bindings = [];
            foreach ($chunk as $id => $bestId) {
                array_push($bindings, $id, $bestId);
            }
            DB::update("UPDATE channels SET better_copy_id = CASE id {$cases} END WHERE id IN ({$marks})", [...$bindings, ...array_keys($chunk)]);
        }

        ListCacheService::bump();

        return count($better);
    }
}
