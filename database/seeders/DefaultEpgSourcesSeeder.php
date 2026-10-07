<?php

namespace Database\Seeders;

use App\Jobs\SyncEpgSource;
use App\Models\EpgSource;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Free XMLTV guides (iptv-epg.org) for the countries the default channels come from.
 * Their channel ids follow iptv-org's tvg-ids. Adds the missing ones by name and queues an import.
 * DatabaseSeeder only calls this while there are no guide sources.
 */
class DefaultEpgSourcesSeeder extends Seeder
{
    public const GUIDES = [
        'Macedonia' => 'https://iptv-epg.org/files/epg-mk.xml.gz',
        'Italy' => 'https://iptv-epg.org/files/epg-it.xml.gz',
        'Serbia' => 'https://iptv-epg.org/files/epg-rs.xml.gz',
        'Croatia' => 'https://iptv-epg.org/files/epg-hr.xml.gz',
        'Bosnia and Herzegovina' => 'https://iptv-epg.org/files/epg-ba.xml.gz',
        'Albania' => 'https://iptv-epg.org/files/epg-al.xml.gz',
    ];

    public function run(): void
    {
        foreach (self::GUIDES as $name => $url) {
            if (EpgSource::where('name', $name)->exists()) {
                continue;
            }

            $source = EpgSource::create(['name' => $name, 'url' => $url, 'status' => Source::STATUS_QUEUED]);
            SyncEpgSource::dispatch($source);
        }
    }
}
