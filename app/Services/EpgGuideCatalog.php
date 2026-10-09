<?php

namespace App\Services;

use App\Jobs\SyncEpgSource;
use App\Models\Channel;
use App\Models\EpgSource;
use App\Models\Source;

/**
 * The free per-country guide files at iptv-epg.org (their channel ids follow iptv-org's tvg-ids, so playlists from
 * iptv-org match them by id), and which of them the channels we have could use: channels whose playlist id ends in a
 * country ("CanalShowsport.ar@SD" → ar) and that have no guide yet.
 */
class EpgGuideCatalog
{
    /** Countries that have a guide file (checked 2026-10-09). The United Kingdom is "gb" here, "uk" in iptv-org ids. */
    public const COUNTRIES = [
        'al' => 'Albania', 'ar' => 'Argentina', 'at' => 'Austria', 'au' => 'Australia', 'ba' => 'Bosnia and Herzegovina',
        'be' => 'Belgium', 'bg' => 'Bulgaria', 'bo' => 'Bolivia', 'br' => 'Brazil', 'ca' => 'Canada', 'ch' => 'Switzerland',
        'cl' => 'Chile', 'co' => 'Colombia', 'cr' => 'Costa Rica', 'cz' => 'Czechia', 'de' => 'Germany',
        'do' => 'Dominican Republic', 'ec' => 'Ecuador', 'eg' => 'Egypt', 'es' => 'Spain', 'fr' => 'France',
        'gb' => 'United Kingdom', 'gr' => 'Greece', 'hr' => 'Croatia', 'hu' => 'Hungary', 'id' => 'Indonesia',
        'il' => 'Israel', 'in' => 'India', 'it' => 'Italy', 'jp' => 'Japan', 'kr' => 'South Korea', 'mk' => 'Macedonia',
        'me' => 'Montenegro', 'mx' => 'Mexico', 'my' => 'Malaysia', 'nl' => 'Netherlands', 'nz' => 'New Zealand',
        'pa' => 'Panama', 'pe' => 'Peru', 'ph' => 'Philippines', 'pl' => 'Poland', 'pt' => 'Portugal', 'py' => 'Paraguay',
        'ro' => 'Romania', 'rs' => 'Serbia', 'ru' => 'Russia', 'sa' => 'Saudi Arabia', 'si' => 'Slovenia', 'th' => 'Thailand',
        'tr' => 'Turkey', 'ua' => 'Ukraine', 'ae' => 'United Arab Emirates', 'us' => 'United States', 'uy' => 'Uruguay',
        've' => 'Venezuela', 'vn' => 'Vietnam', 'za' => 'South Africa',
    ];

    /** A country is suggested when at least this many channels without a guide have its id suffix. */
    public const MIN_CHANNELS = 3;

    public static function url(string $code): string
    {
        return 'https://iptv-epg.org/files/epg-'.$code.'.xml.gz';
    }

    /**
     * Countries worth adding a guide for, most channels first.
     *
     * @return list<array{code: string, name: string, channels: int}>
     */
    public function suggestions(): array
    {
        $have = EpgSource::get(['id', 'url'])->map(fn (EpgSource $s) => $s->country())->filter()->all();
        $counts = [];

        Channel::query()->where('is_active', true)->whereNull('epg_source_id')->where('tvg_id', 'like', '%.__%')->select(['id', 'tvg_id'])
            ->chunkById(5000, function ($channels) use (&$counts) {
                foreach ($channels as $channel) {
                    $code = EpgService::countryOf($channel->tvg_id);
                    if ($code !== null && isset(self::COUNTRIES[$code])) {
                        $counts[$code] = ($counts[$code] ?? 0) + 1;
                    }
                }
            });

        $list = [];
        foreach ($counts as $code => $channels) {
            if ($channels >= self::MIN_CHANNELS && !in_array($code, $have, true)) {
                $list[] = ['code' => $code, 'name' => self::COUNTRIES[$code], 'channels' => $channels];
            }
        }
        usort($list, fn ($a, $b) => $b['channels'] <=> $a['channels']);

        return $list;
    }

    /**
     * Add (and start importing) the guides of these countries; ones we already have are skipped.
     *
     * @param  list<string>  $codes
     * @return int guides added
     */
    public function add(array $codes): int
    {
        $added = 0;

        foreach (array_unique($codes) as $code) {
            if (!isset(self::COUNTRIES[$code]) || EpgSource::where('url', self::url($code))->exists()) {
                continue;
            }

            $source = EpgSource::create(['name' => self::COUNTRIES[$code], 'url' => self::url($code), 'status' => Source::STATUS_QUEUED]);
            SyncEpgSource::dispatch($source);
            $added++;
        }

        return $added;
    }
}
