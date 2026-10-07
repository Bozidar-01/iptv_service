<?php

namespace Database\Seeders;

use App\Models\Bouquet;
use App\Models\Source;
use App\Services\SourceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Default sources so a fresh install has channels to show.
 *
 * - The Macedonian lists ship with the app (database/seeders/playlists) and were checked by hand;
 *   their group-titles become the "MK | ..." categories.
 * - The rest are public free-to-air lists (Free-TV, iptv-org) that update themselves daily.
 *   Many of their streams go offline over time; disable the dead ones in the admin.
 *
 * Adds any default source whose name is missing, then queues an import for it.
 * DatabaseSeeder only calls this while there are no sources at all.
 */
class DefaultSourcesSeeder extends Seeder
{
    public const SOURCES = [
        ['name' => 'Macedonia – TV', 'file' => 'macedonia-tv.m3u', 'bouquet' => 'Macedonia TV'],
        ['name' => 'Macedonia – Music', 'file' => 'macedonia-music.m3u', 'bouquet' => 'Macedonia Music'],
        ['name' => 'Free-TV (world, by country)', 'url' => 'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8', 'bouquet' => 'World'],
        ['name' => 'iptv-org – News', 'url' => 'https://iptv-org.github.io/iptv/categories/news.m3u', 'bouquet' => 'News'],
        ['name' => 'iptv-org – Sports', 'url' => 'https://iptv-org.github.io/iptv/categories/sports.m3u', 'bouquet' => 'Sports'],
        ['name' => 'iptv-org – Movies', 'url' => 'https://iptv-org.github.io/iptv/categories/movies.m3u', 'bouquet' => 'Movies'],
        ['name' => 'iptv-org – Kids', 'url' => 'https://iptv-org.github.io/iptv/categories/kids.m3u', 'bouquet' => 'Kids'],
        ['name' => 'iptv-org – Music', 'url' => 'https://iptv-org.github.io/iptv/categories/music.m3u', 'bouquet' => 'Music'],
        ['name' => 'iptv-org – Documentary', 'url' => 'https://iptv-org.github.io/iptv/categories/documentary.m3u', 'bouquet' => 'Documentary'],
    ];

    public function run(SourceService $sourceService): void
    {
        foreach (self::SOURCES as $default) {
            if (Source::where('name', $default['name'])->exists()) {
                continue;
            }

            $data = [
                'name' => $default['name'],
                'bouquet_id' => Bouquet::firstOrCreate(['name' => $default['bouquet']])->id,
                'skip_vod' => true,
                'auto_sync' => isset($default['url']),
            ];

            if (isset($default['url'])) {
                $data += ['type' => 'url', 'url' => $default['url']];
            } else {
                $path = 'sources/default-'.$default['file'];
                Storage::put($path, file_get_contents(__DIR__.'/playlists/'.$default['file']));
                $data += ['type' => 'file', 'file_path' => $path];
            }

            $sourceService->createSource($data);
        }
    }
}
