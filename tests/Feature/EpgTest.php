<?php

use App\Models\Channel;
use App\Models\EpgProgramme;
use App\Models\EpgSource;
use App\Models\User;
use App\Services\EpgImportService;
use App\Services\EpgService;
use Illuminate\Support\Facades\Http;

function xmltv(string $body): string
{
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv>\n".$body."\n</tv>";
}

function guideXml(): string
{
    $at = fn ($hours) => now()->addHours($hours)->utc()->format('YmdHis').' +0000';

    return xmltv(implode("\n", [
        '<channel id="Rai1.it"><display-name>IT - Rai 1</display-name></channel>',
        '<channel id="rai1.IT"><display-name>Duplicate in other case</display-name></channel>',
        '<channel id="Kanal5.mk"><display-name>MK - Kanal 5</display-name></channel>',
        '<programme start="'.$at(-1).'" stop="'.$at(1).'" channel="Rai1.it"><title>Tale e Quale Show</title><desc>Varietà</desc></programme>',
        '<programme start="'.$at(1).'" stop="'.$at(2).'" channel="Rai1.it"><title>TG1</title></programme>',
        '<programme start="'.$at(-1).'" stop="'.$at(1).'" channel="Kanal5.mk"><title>No Data</title></programme>',
        '<programme start="'.$at(-30).'" stop="'.$at(-29).'" channel="Rai1.it"><title>Long gone</title></programme>',
        '<programme start="'.$at(24 * 10).'" stop="'.$at(24 * 10 + 1).'" channel="Rai1.it"><title>Too far ahead</title></programme>',
    ]));
}

function importGuide(?string $xml = null, bool $gzip = false): EpgSource
{
    $xml ??= guideXml();
    refakeHttp(['guide.test/*' => fn () => Http::response($gzip ? gzencode($xml) : $xml)]);
    $source = EpgSource::create(['name' => 'Italy', 'url' => 'http://guide.test/it.xml'.($gzip ? '.gz' : '')]);
    app(EpgImportService::class)->sync($source);

    return $source->fresh();
}

it('imports channels and programmes inside the guide window, skipping filler', function () {
    $source = importGuide(gzip: true);

    expect($source)->status->toBe('success')->channel_count->toBe(2)->programme_count->toBe(2)
        ->and(EpgProgramme::orderBy('start')->pluck('title')->all())->toBe(['Tale e Quale Show', 'TG1'])
        ->and(EpgProgramme::first()->description)->toBe('Varietà');
});

it('replaces a guide on re-import and records failures', function () {
    importGuide();
    importGuide(); // second source with the same file: its own rows
    expect(EpgProgramme::count())->toBe(4);

    refakeHttp(['guide.test/*' => fn () => Http::response('<html>not a guide</html>')]);
    $source = EpgSource::first();
    expect(fn () => app(EpgImportService::class)->sync($source))->toThrow(RuntimeException::class);
    expect($source->fresh()->status)->toBe('failed')
        ->and(EpgProgramme::where('epg_source_id', $source->id)->count())->toBe(2); // old guide kept
});

it('normalises names for matching', function (string $a, string $b) {
    expect(EpgService::nameKey($a))->toBe(EpgService::nameKey($b));
})->with([
    ['Rai 1 HD', 'IT - Rai 1'],
    ['Канал 5', 'MK - Kanal 5'],
    ['Sitel (480p) [Geo-blocked]', 'MK - Sitel'],
    ['Rai News 24 FHD', 'Rai News 24'],
]);

it('matches channels by tvg-id, by tvg-id without the @ suffix, and by name', function () {
    $source = importGuide();
    $byId = Channel::create(['name' => 'Something else', 'tvg_id' => 'Rai1.it', 'stream_url' => 'http://x.test/1', 'is_active' => true]);
    $bySuffix = Channel::create(['name' => 'K5', 'tvg_id' => 'Kanal5.mk@SD', 'stream_url' => 'http://x.test/2', 'is_active' => true]);
    $byName = Channel::create(['name' => 'Rai 1 HD', 'tvg_id' => '1:0:19:D49:1450:13E:820000:0:0:0:', 'stream_url' => 'http://x.test/3', 'is_active' => true]);
    $none = Channel::create(['name' => 'Unknown TV', 'stream_url' => 'http://x.test/4', 'is_active' => true]);
    $manual = Channel::create(['name' => 'Rai 1 HD', 'stream_url' => 'http://x.test/5', 'is_active' => true, 'epg_manual' => true, 'epg_id' => 'Kanal5.mk']);

    app(EpgService::class)->matchChannels();

    expect($byId->fresh())->epg_id->toBe('Rai1.it')->epg_source_id->toBe($source->id)
        ->and($bySuffix->fresh()->epg_id)->toBe('Kanal5.mk')
        ->and($byName->fresh()->epg_id)->toBe('Rai1.it')
        ->and($none->fresh()->epg_id)->toBeNull()
        ->and($manual->fresh())->epg_id->toBe('Kanal5.mk')->epg_source_id->toBe($source->id)
        ->and(app(EpgService::class)->nowPlaying($byName->fresh())->title)->toBe('Tale e Quale Show');
});

it('serves the guide to the playlist link and the Xtream login', function () {
    importGuide();
    $user = User::factory()->create(['username' => 'tv1', 'password' => bcrypt('secret')]);
    $rai = subscribedChannel($user, ['name' => 'Rai 1 HD']);
    Channel::create(['name' => 'Not mine', 'tvg_id' => 'Kanal5.mk', 'stream_url' => 'http://x.test/9', 'is_active' => true]);
    app(EpgService::class)->matchChannels();

    $playlist = $this->get(route('playlist', ['token' => $user->playlist_token]))->getContent();
    expect($playlist)->toStartWith('#EXTM3U x-tvg-url="'.route('epg', ['token' => $user->playlist_token]).'"')
        ->toContain('tvg-id="Rai1.it"');

    $guide = $this->get(route('epg', ['token' => $user->playlist_token]))->assertOk()->streamedContent();
    expect($guide)->toContain('<channel id="Rai1.it"><display-name>IT - Rai 1</display-name></channel>')
        ->toContain('<title>Tale e Quale Show</title><desc>Varietà</desc>')
        ->not->toContain('Kanal5.mk')
        ->not->toContain('Long gone');
    expect(simplexml_load_string($guide))->not->toBeFalse();

    expect($this->get('/xmltv.php?username=tv1&password=secret')->streamedContent())->toContain('<title>TG1</title>');
    $this->get('/xmltv.php?username=tv1&password=wrong')->assertSee('<tv></tv>', false);

    $short = $this->get('/player_api.php?username=tv1&password=secret&action=get_short_epg&stream_id='.$rai->id.'&limit=1')->json('epg_listings');
    expect($short)->toHaveCount(1)
        ->and(base64_decode($short[0]['title']))->toBe('Tale e Quale Show')
        ->and($short[0]['channel_id'])->toBe('Rai1.it');

    $table = $this->get('/player_api.php?username=tv1&password=secret&action=get_simple_data_table&stream_id='.$rai->id)->json('epg_listings');
    expect(collect($table)->pluck('now_playing')->all())->toBe([1, 0]);

    $streams = json_decode($this->get('/player_api.php?username=tv1&password=secret&action=get_live_streams')->streamedContent(), true);
    expect($streams[0]['epg_channel_id'])->toBe('Rai1.it');
});

it('does not serve a guide for unknown or disabled users', function () {
    $user = User::factory()->create(['status' => 'disabled']);

    $this->get(route('epg', ['token' => $user->playlist_token]))->assertNotFound();
    $this->get(route('epg', ['token' => 'nope']))->assertNotFound();
});

it('runs from the command and the daily schedule', function () {
    importGuide();

    $this->artisan('iptv:sync-epg', ['--now' => true])->expectsOutputToContain('Imported: Italy (2 programmes)')->assertSuccessful();

    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'iptv:sync-epg'));
    expect($event?->expression)->toBe('30 3 * * *');
});

it('parses XMLTV times with and without an offset', function () {
    expect(EpgImportService::parseTime('20261007200000 +0200')->utc()->toDateTimeString())->toBe('2026-10-07 18:00:00')
        ->and(EpgImportService::parseTime('20261007200000')->utc()->toDateTimeString())->toBe('2026-10-07 20:00:00')
        ->and(EpgImportService::parseTime('garbage'))->toBeNull();
});

describe('admin', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('lists guides with coverage and adds, refreshes and deletes them', function () {
        \Illuminate\Support\Facades\Queue::fake();
        $source = importGuide();
        Channel::create(['name' => 'Rai 1', 'stream_url' => 'http://x.test/1', 'is_active' => true]);
        app(EpgService::class)->matchChannels();

        $this->get(route('admin.epg.index'))->assertOk()->assertSee('Italy')->assertSee('Enabled channels with a guide');

        $this->post(route('admin.epg.store'), ['name' => 'Germany', 'url' => 'https://iptv-epg.org/files/epg-de.xml.gz'])->assertSessionHas('success');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncEpgSource::class);

        $this->post(route('admin.epg.sync', $source))->assertSessionHas('success');
        $this->post(route('admin.epg.match'))->assertSessionHas('success', '1 channel has a guide.');

        $this->delete(route('admin.epg.destroy', $source))->assertSessionHas('success');
        expect(EpgProgramme::count())->toBe(0)->and(Channel::first()->epg_source_id)->toBeNull();
    });

    it('sets a guide ID by hand and shows what is on now', function () {
        importGuide();
        $channel = Channel::create(['name' => 'Odd name', 'stream_url' => 'http://x.test/1', 'is_active' => true]);

        $this->put(route('admin.channels.update', $channel), ['name' => 'Odd name', 'stream_url' => 'http://x.test/1', 'is_active' => '1', 'epg_id' => 'Rai1.it']);
        expect($channel->fresh())->epg_id->toBe('Rai1.it')->epg_manual->toBeTrue()->epg_source_id->not->toBeNull();

        $this->get(route('admin.channels.edit', $channel))->assertSee('Tale e Quale Show');

        $this->put(route('admin.channels.update', $channel), ['name' => 'Odd name', 'stream_url' => 'http://x.test/1', 'is_active' => '1', 'epg_id' => '']);
        expect($channel->fresh())->epg_manual->toBeFalse()->epg_id->toBeNull();
    });
});
