<?php

use App\Jobs\CheckStreams;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\ChannelFailoverService;
use App\Services\ChannelHealthService;
use App\Services\StreamCheckService;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const LIVE_PLAYLIST = "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-MEDIA-SEQUENCE:40\n#EXTINF:6.0,\nseg40.ts\n#EXTINF:6.0,\nseg41.ts\n";

function healthChannel(string $url, array $attributes = []): Channel
{
    return Channel::create(array_merge(['name' => $url, 'stream_url' => $url, 'is_active' => true], $attributes));
}

function healthPlayUrl(User $user, Channel $channel): string
{
    return route('play', ['channel_id' => $channel->id, 'token' => app(TokenService::class)->generateToken($user)->token]);
}

describe('reliability', function () {
    it('counts every check and scores the last 7 days', function () {
        $good = healthChannel('http://good.test/a.ts');
        $flaky = healthChannel('http://flaky.test/a.ts');
        $health = app(ChannelHealthService::class);

        $health->recordChecks([$good->id, $flaky->id], []);
        $health->recordChecks([$good->id], [$flaky->id]);
        $health->recordChecks([$good->id], [$flaky->id], []);
        $health->recordChecks([$good->id, $flaky->id], [], [$flaky->id]);

        expect($good->fresh()->reliability)->toBe(100)
            ->and($flaky->fresh()->reliability)->toBe(50)
            ->and($health->summary($flaky))->toBe(['ok' => 2, 'failed' => 2, 'slow' => 1, 'viewer_trouble' => 0]);
    });

    it('leaves days older than a week out of the score and deletes old history', function () {
        $channel = healthChannel('http://old.test/a.ts');
        DB::table('channel_check_days')->insert([
            ['channel_id' => $channel->id, 'day' => today()->subDays(10)->toDateString(), 'ok' => 0, 'failed' => 50],
            ['channel_id' => $channel->id, 'day' => today()->subDays(40)->toDateString(), 'ok' => 0, 'failed' => 9],
        ]);

        app(ChannelHealthService::class)->recordChecks([$channel->id], []);

        expect($channel->fresh()->reliability)->toBe(100)
            ->and(app(ChannelHealthService::class)->prune())->toBe(1);
    });

    it('is counted by the stream check', function () {
        Http::fake(['up.test/*' => fn () => Http::response(str_repeat("\x47", 376), 200, ['Content-Type' => 'video/mp2t']), 'down.test/*' => fn () => Http::response('', 404)]);
        $up = healthChannel('http://up.test/a.ts');
        $down = healthChannel('http://down.test/a.ts');

        app(StreamCheckService::class)->checkChannels([$up->id, $down->id]);

        expect($up->fresh()->reliability)->toBe(100)->and($down->fresh()->reliability)->toBe(0);
    });
});

describe('closer look at HLS', function () {
    it('finds the video playlist of a master playlist, and a playlist without video', function () {
        Http::fake([
            'master.test/live/index.m3u8' => fn () => Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\nlow/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=4000000,RESOLUTION=1920x1080\nhigh/index.m3u8\n"),
            'master.test/live/high/index.m3u8' => fn () => Http::response(LIVE_PLAYLIST),
            'empty.test/live/index.m3u8' => fn () => Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\nv.m3u8\n"),
            'empty.test/live/v.m3u8' => fn () => Http::response('Not found', 404),
        ]);
        $master = healthChannel('http://master.test/live/index.m3u8');
        $empty = healthChannel('http://empty.test/live/index.m3u8');

        $result = app(StreamCheckService::class)->checkChannels([$master->id, $empty->id]);

        expect($result)->toBe(['online' => 1, 'offline' => 1])
            ->and($master->fresh())->hls_sequence->toBe(40)->quality_height->toBe(1080)
            ->and($empty->fresh())->is_active->toBeFalse()->stream_error->toBe('no-video');
        Http::assertSent(fn ($request) => $request->url() === 'http://master.test/live/high/index.m3u8');
    });

    it('tries the next picture size when the best one is missing, as players do', function () {
        Http::fake([
            'abr.test/master.m3u8' => fn () => Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=400000\nlow.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=4000000\nhigh.m3u8\n"),
            'abr.test/high.m3u8' => fn () => Http::response('', 404),
            'abr.test/low.m3u8' => fn () => Http::response(LIVE_PLAYLIST),
        ]);
        $channel = healthChannel('http://abr.test/master.m3u8');

        app(StreamCheckService::class)->checkChannels([$channel->id]);

        expect($channel->fresh())->is_active->toBeTrue()->stream_error->toBeNull()->hls_sequence->toBe(40);
    });

    it('does not switch a channel off when only its piece of video fails, but counts it as slow', function () {
        Http::fake(['seg.test/*.m3u8' => fn () => Http::response(LIVE_PLAYLIST), 'seg.test/seg41.ts' => Http::failedConnection()]);
        $channel = healthChannel('http://seg.test/a.m3u8', ['alert_on_down' => true]);

        app(StreamCheckService::class)->checkChannels([$channel->id]);

        expect($channel->fresh())->is_active->toBeTrue()->stream_status->toBe('online')->stream_speed->toBe(0);
    });

    it('does not judge a playlist that does not answer the closer look', function () {
        Http::fake(['busy.test/master.m3u8' => fn () => Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=400000\nv.m3u8\n"), 'busy.test/v.m3u8' => Http::failedConnection()]);
        $channel = healthChannel('http://busy.test/master.m3u8');

        app(StreamCheckService::class)->checkChannels([$channel->id]);

        expect($channel->fresh())->is_active->toBeTrue()->stream_error->toBeNull();
    });

    it('switches off a stream whose picture has stopped', function () {
        Http::fake(['frozen.test/*' => fn () => Http::response(LIVE_PLAYLIST)]);
        $channel = healthChannel('http://frozen.test/a.m3u8', ['hls_sequence' => 40, 'stream_status' => 'online', 'stream_checked_at' => now()->subMinutes(15)]);

        app(StreamCheckService::class)->checkChannels([$channel->id]);

        expect($channel->fresh())->is_active->toBeFalse()->stream_error->toBe('frozen')
            ->and(StreamCheckService::describeError('frozen'))->toBe('The picture has stopped: the stream sends no new video');
    });

    it('does not call a stream frozen when the last check was moments ago or it moved on', function () {
        Http::fake(['live.test/*' => fn () => Http::response(LIVE_PLAYLIST)]);
        $recent = healthChannel('http://live.test/a.m3u8', ['hls_sequence' => 40, 'stream_checked_at' => now()->subSeconds(20)]);
        $moved = healthChannel('http://live.test/b.m3u8', ['hls_sequence' => 39, 'stream_checked_at' => now()->subHour()]);

        app(StreamCheckService::class)->checkChannels([$recent->id, $moved->id]);

        expect($recent->fresh()->is_active)->toBeTrue()->and($moved->fresh()->is_active)->toBeTrue();
    });

    it('measures the speed of channels people watch', function () {
        Http::fake(['watched.test/*.m3u8' => fn () => Http::response(LIVE_PLAYLIST), 'watched.test/seg41.ts' => fn () => Http::response(str_repeat('x', 1000))]);
        $watched = healthChannel('http://watched.test/a.m3u8');
        $other = healthChannel('http://watched.test/b.m3u8');
        StreamLog::create(['user_id' => User::factory()->create()->id, 'channel_id' => $watched->id, 'started_at' => now()->subDay()]);

        app(StreamCheckService::class)->checkChannels([$watched->id, $other->id]);

        Http::assertSent(fn ($request) => $request->url() === 'http://watched.test/seg41.ts');
        Http::assertSentCount(5); // two playlists, the closer look at both, one piece of video
    });

    it('works out how fast video arrives compared to how long it plays', function () {
        // 1 MB of a 2 MB piece in 0.5 s after 0.5 s to the first byte: the whole piece takes 1.5 s for 6 s of video
        expect(StreamCheckService::speedPercent(1_000_000, 0.5, 1.0, 2_000_000, 6.0))->toBe(400)
            // 8 s for 6 s of video: viewers wait
            ->and(StreamCheckService::speedPercent(1_000_000, 2.0, 5.0, 2_000_000, 6.0))->toBe(75)
            ->and(StreamCheckService::speedPercent(0, 0.1, 0.2, 100, 6.0))->toBeNull()
            ->and(StreamCheckService::parseMediaPlaylist(LIVE_PLAYLIST))->toBe(['sequence' => 40, 'target' => 6, 'ended' => false, 'segment' => 'seg41.ts', 'duration' => 6.0])
            ->and(StreamCheckService::resolveUrl('http://a.test/x/y/index.m3u8?t=1', 'seg.ts'))->toBe('http://a.test/x/y/seg.ts')
            ->and(StreamCheckService::resolveUrl('http://a.test:8080/x/index.m3u8', '/abs/seg.ts'))->toBe('http://a.test:8080/abs/seg.ts')
            ->and(StreamCheckService::resolveUrl('https://a.test/live/x/hls/index.m3u8', '../_shared/seg.ts'))->toBe('https://a.test/live/x/_shared/seg.ts')
            ->and(StreamCheckService::variants("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\na.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=9\nb.m3u8\n"))->toBe([['b.m3u8', 9], ['a.m3u8', 1]]);
    });
});

describe('backups', function () {
    it('picks the chosen backup, then copies that are not slow, the most reliable, the best picture', function () {
        $dead = healthChannel('http://a.test/1', ['tvg_id' => 'Rai1.it']);
        $slow = healthChannel('http://b.test/1', ['tvg_id' => 'Rai1.it', 'stream_status' => 'online', 'reliability' => 100, 'stream_speed' => 60]);
        $shaky = healthChannel('http://c.test/1', ['tvg_id' => 'rai1.it', 'stream_status' => 'online', 'reliability' => 70]);
        $steady = healthChannel('http://d.test/1', ['tvg_id' => 'Rai1.it', 'stream_status' => 'online', 'reliability' => 99, 'name' => 'Rai 1 SD']);
        $failover = app(ChannelFailoverService::class);

        expect($failover->ranked($dead)->pluck('id')->all())->toBe([$steady->id, $shaky->id, $slow->id]);

        $dead->update(['backup_channel_id' => $shaky->id]);
        expect($failover->ranked($dead->fresh())->first()->id)->toBe($shaky->id);
    });

    it('moves a slow stream to a faster copy and back when it is fast again', function () {
        $channel = healthChannel('http://slow.test/a.ts', ['tvg_id' => 'X.it']);
        $copy = healthChannel('http://fast.test/a.ts', ['tvg_id' => 'X.it', 'stream_status' => 'online']);
        $checker = Mockery::mock(StreamCheckService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $checker->shouldReceive('probe')->andReturn([$channel->id => null]);
        $checker->shouldReceive('inspectHls')->andReturn([$channel->id => ['error' => null, 'speed' => 70, 'sequence' => 1]]);

        $checker->checkChannels([$channel->id]);
        expect($channel->fresh())->using_backup_id->toBe($copy->id)->stream_speed->toBe(70)->is_active->toBeTrue();

        $checker = Mockery::mock(StreamCheckService::class)->makePartial();
        $checker->shouldReceive('probe')->andReturn([$channel->id => null]);
        $checker->shouldReceive('inspectHls')->andReturn([$channel->id => ['error' => null, 'speed' => 300, 'sequence' => 2]]);
        $checker->checkChannels([$channel->id]);
        expect($channel->fresh())->using_backup_id->toBeNull()->stream_speed->toBe(300);
    });

    it('checks channels playing a backup every 15 minutes', function () {
        $playing = healthChannel('http://a.test/1', ['using_backup_id' => healthChannel('http://b.test/1')->id]);
        healthChannel('http://c.test/1');

        expect(app(StreamCheckService::class)->checkableQuery(watchedOnly: true)->pluck('id')->all())->toBe([$playing->id]);
    });
});

describe('viewers', function () {
    it('moves a device that keeps retrying to the next working copy, and around to its own stream', function () {
        Queue::fake();
        $user = User::factory()->create();
        $channel = subscribedChannel($user, ['stream_url' => 'http://own.test/a.ts', 'tvg_id' => 'Y.it']);
        $copy = healthChannel('http://copy.test/a.ts', ['tvg_id' => 'Y.it', 'stream_status' => 'online']);
        $url = healthPlayUrl($user, $channel);

        $this->get($url)->assertRedirect('http://own.test/a.ts');
        $this->get($url)->assertRedirect('http://own.test/a.ts');
        $this->get($url)->assertRedirect('http://copy.test/a.ts');
        $this->get($url)->assertRedirect('http://own.test/a.ts');

        // Other viewers keep the channel's own stream
        expect($channel->fresh()->using_backup_id)->toBeNull();
    });

    it('counts trouble once per viewer and day, and checks the channel soon', function () {
        Queue::fake();
        $user = User::factory()->create();
        $channel = subscribedChannel($user, ['stream_url' => 'http://own.test/a.ts']);
        $url = healthPlayUrl($user, $channel);

        foreach (range(1, 5) as $i) {
            $this->get($url);
        }

        expect(app(ChannelHealthService::class)->troubleCounts())->toBe([$channel->id => 1]);
        Queue::assertPushed(CheckStreams::class, fn ($job) => $job->channelIds === [$channel->id]);
        Queue::assertPushed(CheckStreams::class, 1);
    });

    it('does not count streams that refresh their playlist through the play link', function () {
        $user = User::factory()->create();
        $channel = subscribedChannel($user, ['stream_url' => 'http://own.test/index.m3u8']);
        $url = healthPlayUrl($user, $channel);

        foreach (range(1, 4) as $i) {
            $this->get($url)->assertRedirect('http://own.test/index.m3u8');
        }

        expect(app(ChannelHealthService::class)->troubleCounts())->toBe([]);
    });

    it('does not quick-check receiver channels', function () {
        Queue::fake();
        $user = User::factory()->create();
        $source = Source::create(['name' => 'Box', 'type' => 'receiver', 'url' => 'http://box.test:8001', 'relay_secret' => 's']);
        $channel = subscribedChannel($user, ['stream_url' => 'http://box.test:8001/1:0:1', 'source_id' => $source->id]);

        app(ChannelHealthService::class)->recordTrouble($user, $channel);

        Queue::assertNothingPushed();
    });

    it('marks channels several viewers had trouble with on the Channels page', function () {
        $this->actingAs(User::factory()->admin()->create());
        $bad = healthChannel('http://bad.test/a.ts', ['name' => 'Troubled One']);
        healthChannel('http://ok.test/a.ts', ['name' => 'Fine One']);
        DB::table('channel_check_days')->insert(['channel_id' => $bad->id, 'day' => today()->toDateString(), 'viewer_trouble' => 2]);

        $this->get(route('admin.channels.index', ['status' => 'trouble']))
            ->assertOk()->assertSee('Troubled One')->assertSee('Viewers had trouble')->assertDontSee('Fine One');
    });

    it('shows how well a channel works on its edit page', function () {
        $this->actingAs(User::factory()->admin()->create());
        $channel = healthChannel('http://a.test/a.ts', ['tvg_id' => 'Z.it', 'stream_speed' => 80, 'reliability' => 75]);
        healthChannel('http://b.test/a.ts', ['tvg_id' => 'Z.it', 'stream_status' => 'online', 'name' => 'Copy Z']);
        app(ChannelHealthService::class)->recordChecks([$channel->id], []);

        $this->get(route('admin.channels.edit', $channel))->assertOk()
            ->assertSee('How well it works')->assertSee('Slow')->assertSee('Copy Z');
    });
});
