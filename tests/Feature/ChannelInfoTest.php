<?php

use App\Jobs\FillChannelInfo;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\EpgSource;
use App\Models\Source;
use App\Models\User;
use App\Services\CatchupService;
use App\Services\ChannelCatalogService;
use App\Services\EpgService;
use App\Services\PlaylistOptionsService;
use App\Services\SourceSyncService;
use App\Services\TokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakeCatalog(): void
{
    Http::fake([
        ChannelCatalogService::CHANNELS_URL => fn () => Http::response([
            ['id' => 'Kanal5.mk', 'name' => 'Kanal 5', 'alt_names' => ['Канал 5'], 'country' => 'MK', 'closed' => null],
            ['id' => 'Sport1.de', 'name' => 'Sport 1', 'alt_names' => [], 'country' => 'DE', 'closed' => null],
            ['id' => 'Sport1.hu', 'name' => 'Sport 1', 'alt_names' => [], 'country' => 'HU', 'closed' => null],
            ['id' => 'BBCOne.uk', 'name' => 'BBC One', 'alt_names' => [], 'country' => 'UK', 'closed' => null],
            ['id' => 'Gone.mk', 'name' => 'Gone TV', 'alt_names' => [], 'country' => 'MK', 'closed' => '2020-01-01'],
        ]),
        ChannelCatalogService::LOGOS_URL => fn () => Http::response([
            ['channel' => 'Kanal5.mk', 'feed' => 'SD', 'in_use' => true, 'width' => 900, 'url' => 'https://logo.test/k5-feed.png'],
            ['channel' => 'Kanal5.mk', 'feed' => null, 'in_use' => true, 'width' => 200, 'url' => 'https://logo.test/k5.png'],
            ['channel' => 'Sport1.de', 'feed' => null, 'in_use' => true, 'width' => 200, 'url' => 'https://logo.test/s1de.png'],
            ['channel' => 'Sport1.hu', 'feed' => null, 'in_use' => true, 'width' => 200, 'url' => 'https://logo.test/s1hu.png'],
            ['channel' => 'BBCOne.uk', 'feed' => null, 'in_use' => true, 'width' => 200, 'url' => 'https://logo.test/bbc.png'],
        ]),
    ]);
}

afterEach(fn () => File::delete(app(ChannelCatalogService::class)->indexPath()));

describe('logos and guide ids', function () {
    it('fills in what the playlist lacks, by name and country, and leaves the source\'s own alone', function () {
        fakeCatalog();
        $kanal5 = Channel::create(['name' => 'MK - Kanal 5 HD', 'stream_url' => 'http://x.test/1']);
        $sportDe = Channel::create(['name' => 'Sport 1', 'stream_url' => 'http://x.test/2', 'category_id' => Category::create(['name' => 'Germany'])->id]);
        $sportUnknown = Channel::create(['name' => 'Sport 1', 'stream_url' => 'http://x.test/3']);
        $own = Channel::create(['name' => 'BBC One', 'stream_url' => 'http://x.test/4', 'logo_url' => 'https://mine.test/bbc.png', 'tvg_id' => 'BBCOne.uk@HD']);
        $closed = Channel::create(['name' => 'Gone TV', 'stream_url' => 'http://x.test/5']);
        $catalog = app(ChannelCatalogService::class);

        $catalog->refresh();
        expect($catalog->fill())->toBe(['logos' => 2, 'ids' => 2]);

        expect($kanal5->fresh())->auto_logo_url->toBe('https://logo.test/k5.png')->auto_tvg_id->toBe('Kanal5.mk')
            ->and($kanal5->fresh()->logo())->toBe('https://logo.test/k5.png')
            ->and($kanal5->fresh()->guideId())->toBe('Kanal5.mk')
            ->and($sportDe->fresh()->auto_tvg_id)->toBe('Sport1.de')
            // Two countries have "Sport 1" and nothing says which: left alone
            ->and($sportUnknown->fresh()->auto_tvg_id)->toBeNull()
            ->and($own->fresh())->auto_logo_url->toBeNull()->logo()->toBe('https://mine.test/bbc.png')
            ->and($closed->fresh()->auto_tvg_id)->toBeNull()
            ->and($catalog->status())->toMatchArray(['channels' => 4, 'logos' => 4, 'error' => null]);
    });

    it('lets the TV guide match by the guide id it found', function () {
        fakeCatalog();
        $guide = EpgSource::create(['name' => 'G', 'url' => 'http://epg.test/mk.xml']);
        EpgChannel::create(['epg_source_id' => $guide->id, 'xmltv_id' => 'Kanal5.mk', 'name' => 'Something else', 'name_key' => 'somethingelse']);
        $channel = Channel::create(['name' => 'K-5 Skopje', 'stream_url' => 'http://x.test/1', 'auto_tvg_id' => 'Kanal5.mk']);

        app(EpgService::class)->matchChannels();

        expect($channel->fresh()->epg_id)->toBe('Kanal5.mk');
    });

    it('does nothing without the database, and the admin can look up now', function () {
        Queue::fake();
        Channel::create(['name' => 'Kanal 5', 'stream_url' => 'http://x.test/1']);

        expect(app(ChannelCatalogService::class)->fill())->toBe(['logos' => 0, 'ids' => 0]);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.playlist-options.fill-info'))->assertSessionHas('success');
        Queue::assertPushed(FillChannelInfo::class, fn ($job) => $job->download === true);
    });

    it('records a failed download', function () {
        Http::fake([ChannelCatalogService::CHANNELS_URL => fn () => Http::response('', 500)]);

        expect(fn () => app(ChannelCatalogService::class)->refresh())->toThrow(RuntimeException::class);
        expect(app(ChannelCatalogService::class)->status()['error'])->toContain('HTTP 500');
    });
});

describe('quality in names and better copies', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->bouquet = Bouquet::create(['name' => 'Pack']);
        $this->user->bouquets()->attach($this->bouquet);
    });

    function copyIn(Bouquet $bouquet, array $attributes): Channel
    {
        $channel = Channel::create(array_merge(['stream_url' => 'http://x.test/'.uniqid(), 'is_active' => true, 'stream_status' => 'online'], $attributes));
        $bouquet->channels()->attach($channel);

        return $channel;
    }

    it('adds the measured quality to names in playlists and apps', function () {
        copyIn($this->bouquet, ['name' => 'Rai 1 HD', 'quality_height' => 1080]);
        copyIn($this->bouquet, ['name' => 'Plain', 'quality_height' => null]);
        app(PlaylistOptionsService::class)->save(['quality_in_names' => true]);

        $playlist = $this->get(route('playlist', ['token' => $this->user->playlist_token]))->streamedContent();
        $streams = json_decode($this->get(route('xtream.api', ['username' => $this->user->username, 'password' => 'password', 'action' => 'get_live_streams']))->streamedContent(), true);

        expect($playlist)->toContain(',Rai 1 FHD')->toContain(',Plain')
            ->and(array_column($streams, 'name'))->toContain('Rai 1 FHD');
    });

    it('lists only the better copy when the other one is in no other bouquet, and it still plays', function () {
        $hd = copyIn($this->bouquet, ['name' => 'Nova HD', 'tvg_id' => 'Nova.rs', 'quality_height' => 1080]);
        $sd = copyIn($this->bouquet, ['name' => 'Nova', 'tvg_id' => 'nova.rs', 'quality_height' => 576]);
        $elsewhere = copyIn(Bouquet::create(['name' => 'Other']), ['name' => 'Nova SD', 'tvg_id' => 'Nova.rs', 'quality_height' => 576]);
        $this->bouquet->channels()->attach($elsewhere);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.playlist-options.update'), ['quality_in_names' => '0', 'hide_worse_copies' => '1'])->assertSessionHas('success');

        expect($sd->fresh()->better_copy_id)->toBe($hd->id)
            // Also in a bouquet the HD copy is not in: nobody may lose it
            ->and($elsewhere->fresh()->better_copy_id)->toBeNull();

        $playlist = $this->get(route('playlist', ['token' => $this->user->playlist_token]))->streamedContent();
        expect($playlist)->toContain(',Nova HD')->not->toContain(",Nova\n")->toContain(',Nova SD');

        $this->get(route('play', ['channel_id' => $sd->id, 'token' => app(TokenService::class)->generateToken($this->user)->token]))->assertRedirect($sd->stream_url);
    });

    it('shows the options page', function () {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.playlist-options.index'))->assertOk()
            ->assertSee('Show the picture quality in channel names')->assertSee('Number all channels again')->assertSee('Logos and guide IDs');
    });
});

describe('catch-up', function () {
    it('reads what the provider offers from its playlist', function () {
        expect(CatchupService::fromAttributes(['catchup' => 'xc', 'catchup-days' => '3'], 'http://p.test:8080/u/p/77'))->toBe(['xc', 3, null])
            ->and(CatchupService::fromAttributes(['tvg-rec' => '7'], 'http://p.test:8080/live/u/p/77.ts'))->toBe(['xc', 7, null])
            ->and(CatchupService::fromAttributes(['timeshift' => '2'], 'http://cdn.test/live/index.m3u8'))->toBe(['shift', 2, null])
            ->and(CatchupService::fromAttributes(['catchup' => 'default'], 'http://cdn.test/a'))->toBe([null, null, null])
            ->and(CatchupService::fromAttributes(['catchup' => 'append', 'catchup-source' => '?start={utc}'], 'http://cdn.test/a'))->toBe(['append', 5, '?start={utc}'])
            ->and(CatchupService::fromAttributes([], 'http://cdn.test/a'))->toBe([null, null, null]);
    });

    it('imports catch-up and offers it to apps', function () {
        Queue::fake();
        Http::fake(['provider.test/*' => fn () => Http::response("#EXTM3U\n#EXTINF:-1 tvg-id=\"A.it\" catchup=\"shift\" catchup-days=\"4\",Arch\nhttp://cdn.test/arch/index.m3u8\n#EXTINF:-1,Plain\nhttp://cdn.test/plain.ts\n")]);
        $source = Source::create(['name' => 'P', 'type' => 'url', 'url' => 'http://provider.test/list.m3u']);
        app(SourceSyncService::class)->sync($source);
        $user = User::factory()->create();
        $bouquet = Bouquet::create(['name' => 'All']);
        $bouquet->channels()->attach(Channel::pluck('id'));
        $user->bouquets()->attach($bouquet);
        $arch = Channel::where('name', 'Arch')->first();

        expect($arch)->catchup->toBe('shift')->catchup_days->toBe(4);

        $playlist = $this->get(route('playlist', ['token' => $user->playlist_token]))->streamedContent();
        expect($playlist)->toMatch('#catchup="default" catchup-days="4" catchup-source="http://localhost:8000/catchup/'.$arch->id.'/[A-Za-z0-9]{32}_[0-9a-z]+\?utc=\{utc\}&duration=\{duration\}"#')
            ->and(substr_count($playlist, 'catchup='))->toBe(1);

        $streams = collect(json_decode($this->get(route('xtream.api', ['username' => $user->username, 'password' => 'password', 'action' => 'get_live_streams']))->streamedContent(), true))->keyBy('name');
        expect($streams['Arch'])->toMatchArray(['tv_archive' => 1, 'tv_archive_duration' => 4])
            ->and($streams['Plain']['tv_archive'])->toBe(0);
    });

    it('sends apps to the provider\'s archive, and checks access first', function () {
        $user = User::factory()->create();
        $shift = subscribedChannel($user, ['stream_url' => 'http://cdn.test/arch/index.m3u8', 'catchup' => 'shift', 'catchup_days' => 3]);
        $xc = subscribedChannel($user, ['stream_url' => 'http://p.test:8080/live/u/p/77.ts', 'catchup' => 'xc', 'catchup_days' => 3]);
        $none = subscribedChannel($user, ['stream_url' => 'http://cdn.test/none.ts']);
        $token = app(TokenService::class)->generateToken($user)->token;
        $start = now()->subHours(2)->startOfMinute();

        $this->get(route('catchup', ['channel_id' => $shift->id, 'token' => $token, 'utc' => $start->timestamp, 'duration' => 1800]))
            ->assertRedirect('http://cdn.test/arch/index.m3u8?utc='.$start->timestamp.'&lutc='.now()->timestamp);
        $this->get(route('catchup', ['channel_id' => $xc->id, 'token' => $token, 'utc' => $start->timestamp, 'duration' => 1800]))
            ->assertRedirect('http://p.test:8080/timeshift/u/p/30/'.$start->copy()->utc()->format('Y-m-d:H-i').'/77.ts');
        $this->get(route('catchup', ['channel_id' => $none->id, 'token' => $token, 'utc' => $start->timestamp]))->assertNotFound();
        // Older than the archive
        $this->get(route('catchup', ['channel_id' => $shift->id, 'token' => $token, 'utc' => now()->subDays(5)->timestamp]))->assertNotFound();
        $this->get(route('catchup', ['channel_id' => $shift->id, 'token' => 'nope', 'utc' => $start->timestamp]))->assertUnauthorized();

        // Xtream apps
        $this->get(route('xtream.timeshift', ['username' => $user->username, 'password' => 'password', 'duration' => 60, 'start' => $start->format('Y-m-d:H-i'), 'stream' => $xc->id.'.ts']))
            ->assertRedirect('http://p.test:8080/timeshift/u/p/60/'.$start->format('Y-m-d:H-i').'/77.ts');
        $this->get(route('xtream.timeshift-php', ['username' => $user->username, 'password' => 'password', 'stream' => $shift->id, 'start' => $start->format('Y-m-d:H-i'), 'duration' => 30]))
            ->assertRedirect();
    });

    it('denies catch-up to users who may not watch the channel', function () {
        $user = User::factory()->expired()->create();
        $channel = subscribedChannel($user, ['catchup' => 'shift']);

        $this->get(route('catchup', ['channel_id' => $channel->id, 'token' => app(TokenService::class)->generateToken($user)->token, 'utc' => now()->subHour()->timestamp]))->assertForbidden();
    });

    it('fills in the placeholders apps and providers use', function () {
        Carbon::setTestNow('2026-10-19 12:00:00');
        $start = Carbon::parse('2026-10-19 10:30:00');

        expect(CatchupService::fill('/arch?s={utc}&e={utcend}&d={duration}&m={duration:60}&o={offset:60}', $start, 1800))
            ->toBe('/arch?s='.$start->timestamp.'&e='.($start->timestamp + 1800).'&d=1800&m=30&o=90')
            ->and(CatchupService::fill('/{Y}/{m}/{d}/{H}-{M}-{S}.ts?now=${now}', $start, 60))->toBe('/2026/10/19/10-30-00.ts?now='.now()->timestamp)
            ->and(CatchupService::fill('/x?t={utc:YmdHMS}', $start, 60))->toBe('/x?t=20261019103000');

        $channel = new Channel(['stream_url' => 'http://fl.test/ch1/index.m3u8', 'catchup' => 'flussonic', 'catchup_days' => 2]);
        expect(app(CatchupService::class)->providerUrl($channel, $start, 3600))->toBe('http://fl.test/ch1/archive-'.$start->timestamp.'-3600.m3u8');
    });
});
