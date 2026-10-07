<?php

use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Source;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const PLAYLIST = <<<'M3U'
#EXTM3U
#EXTINF:-1 tvg-id="s1" tvg-logo="http://logo.test/s1.png" group-title="Sports",Sport One
http://provider.test/live/u/p/1.ts
#EXTINF:-1 group-title="Sports",Sport Two
http://provider.test/live/u/p/2.ts
#EXTINF:-1 group-title="News",News 24
http://provider.test/live/u/p/3.ts
#EXTINF:-1 group-title="Movies",Some Film
http://provider.test/movie/u/p/4.mkv
M3U;

function urlSource(array $attributes = []): Source
{
    return Source::create(array_merge([
        'name' => 'Provider',
        'type' => 'url',
        'url' => 'http://provider.test/get.php',
        'skip_vod' => true,
    ], $attributes));
}

function syncSource(Source $source): void
{
    app(SourceSyncService::class)->sync($source);
}

it('imports live channels from a URL and creates categories', function () {
    Http::fake(['provider.test/*' => fn () => Http::response(PLAYLIST)]);
    $source = urlSource();

    syncSource($source);

    expect($source->fresh())
        ->status->toBe(Source::STATUS_SUCCESS)
        ->channel_count->toBe(3)
        ->last_synced_at->not->toBeNull();

    $sport = Channel::where('name', 'Sport One')->first();
    expect($sport->stream_url)->toBe('http://provider.test/live/u/p/1.ts')
        ->and($sport->logo_url)->toBe('http://logo.test/s1.png')
        ->and($sport->tvg_id)->toBe('s1')
        ->and($sport->category->name)->toBe('Sports')
        ->and($sport->source_id)->toBe($source->id)
        ->and($sport->is_active)->toBeTrue()
        ->and(Category::pluck('name')->sort()->values()->all())->toBe(['News', 'Sports'])
        ->and(Channel::where('name', 'Some Film')->exists())->toBeFalse();
});

it('includes VOD entries when skip_vod is off', function () {
    Http::fake(['provider.test/*' => fn () => Http::response(PLAYLIST)]);
    $source = urlSource(['skip_vod' => false]);

    syncSource($source);

    expect($source->fresh()->channel_count)->toBe(4);
});

it('imports from an uploaded file', function () {
    Storage::fake();
    Storage::put('sources/test.m3u', PLAYLIST);
    $source = Source::create(['name' => 'File', 'type' => 'file', 'file_path' => 'sources/test.m3u', 'skip_vod' => true]);

    syncSource($source);

    expect($source->fresh()->channel_count)->toBe(3);
});

it('updates, keeps admin choices and removes vanished channels on re-sync', function () {
    Http::fakeSequence('provider.test/*')
        ->push(PLAYLIST)
        ->push(<<<'M3U'
            #EXTM3U
            #EXTINF:-1 group-title="Sports",Sport One
            http://provider.test/live/u/p/NEW-1.ts
            #EXTINF:-1 group-title="Sports",Sport Two
            http://provider.test/live/u/p/2.ts
            M3U);
    $source = urlSource();

    syncSource($source);
    $sportOne = Channel::where('name', 'Sport One')->first();
    Channel::where('name', 'Sport Two')->update(['is_active' => false]);

    $this->travel(1)->minutes();
    syncSource($source);

    expect($sportOne->fresh()->stream_url)->toBe('http://provider.test/live/u/p/NEW-1.ts')
        ->and(Channel::where('name', 'Sport Two')->value('is_active'))->toBeFalse()
        ->and(Channel::where('name', 'News 24')->exists())->toBeFalse()
        ->and($source->fresh()->channel_count)->toBe(2);
});

it('does not touch channels of other sources or manual channels', function () {
    Http::fake(['provider.test/*' => fn () => Http::response(PLAYLIST)]);
    $manual = Channel::create(['name' => 'Manual', 'stream_url' => 'http://x.test/m', 'is_active' => true]);
    $other = urlSource(['name' => 'Other']);
    syncSource($other);

    $this->travel(1)->minutes();
    syncSource(urlSource());

    expect($manual->fresh())->not->toBeNull()
        ->and($other->channels()->count())->toBe(3);
});

it('keeps duplicate names in one playlist as separate channels', function () {
    Http::fake(['provider.test/*' => Http::response("#EXTM3U\n#EXTINF:-1,Same\nhttp://p.test/1\n#EXTINF:-1,Same\nhttp://p.test/2\n")]);
    $source = urlSource();

    syncSource($source);

    expect(Channel::where('name', 'Same')->pluck('stream_url')->sort()->values()->all())
        ->toBe(['http://p.test/1', 'http://p.test/2']);
});

it('adds imported channels to the source bouquet without duplicates', function () {
    Http::fake(['provider.test/*' => fn () => Http::response(PLAYLIST)]);
    $bouquet = Bouquet::create(['name' => 'Provider pack']);
    $source = urlSource(['bouquet_id' => $bouquet->id]);

    syncSource($source);
    $this->travel(1)->minutes();
    syncSource($source);

    expect($bouquet->channels()->count())->toBe(3);
});

it('marks the source as failed when the download fails', function () {
    Http::fake(['provider.test/*' => Http::response('nope', 404)]);
    $source = urlSource();

    expect(fn () => syncSource($source))->toThrow(RuntimeException::class);

    expect($source->fresh())
        ->status->toBe(Source::STATUS_FAILED)
        ->last_error->toContain('HTTP 404');
});

it('fails on an empty playlist without deleting existing channels', function () {
    Http::fakeSequence('provider.test/*')->push(PLAYLIST)->push('');
    $source = urlSource();
    syncSource($source);

    $this->travel(1)->minutes();
    expect(fn () => syncSource($source))->toThrow(RuntimeException::class);

    expect($source->fresh()->status)->toBe(Source::STATUS_FAILED)
        ->and($source->channels()->count())->toBe(3);
});

it('syncs from the artisan command', function () {
    Http::fake(['provider.test/*' => fn () => Http::response(PLAYLIST)]);
    $source = urlSource();

    $this->artisan('iptv:sync-sources', ['--now' => true])->assertSuccessful();

    expect($source->fresh()->channel_count)->toBe(3);
});

it('only picks up due auto-sync sources with --due', function () {
    Http::fake(['provider.test/*' => fn () => Http::response(PLAYLIST)]);
    $fresh = urlSource(['name' => 'Fresh', 'last_synced_at' => now()->subHour()]);
    $stale = urlSource(['name' => 'Stale', 'last_synced_at' => now()->subDays(2)]);
    $manual = urlSource(['name' => 'Manual', 'auto_sync' => false]);

    $this->artisan('iptv:sync-sources', ['--due' => true, '--now' => true])->assertSuccessful();

    expect($stale->fresh()->status)->toBe(Source::STATUS_SUCCESS)
        ->and($fresh->fresh()->status)->toBe(Source::STATUS_PENDING)
        ->and($manual->fresh()->status)->toBe(Source::STATUS_PENDING);
});
