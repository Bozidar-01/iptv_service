<?php

use App\Jobs\CheckStreams;
use App\Models\Channel;
use App\Models\Source;
use App\Models\User;
use App\Services\StreamCheckService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function streamChannel(string $url, array $attributes = []): Channel
{
    return Channel::create(array_merge(['name' => $url, 'stream_url' => $url, 'is_active' => true], $attributes));
}

function fakeStreams(): void
{
    Http::fake([
        'live.test/*' => fn () => Http::response("#EXTM3U\n#EXT-X-VERSION:3\nseg1.ts\n", 200, ['Content-Type' => 'text/plain']),
        'ts.test/*' => fn () => Http::response(str_repeat("\x47", 376), 200, ['Content-Type' => 'video/mp2t']),
        'gone.test/*' => fn () => Http::response('Not found', 404),
        'html.test/*' => fn () => Http::response('<html>Blocked in your country</html>', 200, ['Content-Type' => 'text/html']),
        'down.test/*' => Http::failedConnection(),
    ]);
}

it('decides what counts as a working stream', function (int $status, string $type, string $body, bool $online) {
    expect(app(StreamCheckService::class)->isOnline($status, $type, $body))->toBe($online);
})->with([
    'hls playlist' => [200, 'application/vnd.apple.mpegurl', "#EXTM3U\n", true],
    'playlist served as text' => [200, 'text/plain', "#EXTM3U\n", true],
    'mpeg-ts' => [200, 'video/mp2t', 'x', true],
    'unknown type with data' => [200, '', str_repeat('x', 188), true],
    'html page' => [200, 'text/html', '<html></html>', false],
    'html without type' => [200, '', '<!DOCTYPE html>', false],
    'empty body' => [200, '', '', false],
    'forbidden' => [403, 'video/mp2t', 'x', false],
    'not found' => [404, 'text/plain', "#EXTM3U\n", false],
]);

it('switches dead channels off and keeps working ones on', function () {
    fakeStreams();
    $live = streamChannel('http://live.test/a.m3u8');
    $ts = streamChannel('http://ts.test/a.ts');
    $gone = streamChannel('http://gone.test/a.m3u8');
    $html = streamChannel('http://html.test/a.m3u8');
    $down = streamChannel('http://down.test/a.m3u8');

    $result = app(StreamCheckService::class)->checkChannels(Channel::pluck('id')->all());

    expect($result)->toBe(['online' => 2, 'offline' => 3]);
    foreach ([$live, $ts] as $channel) {
        expect($channel->fresh())->is_active->toBeTrue()->auto_disabled->toBeFalse()->stream_status->toBe('online')->stream_checked_at->not->toBeNull();
    }
    foreach ([$gone, $html, $down] as $channel) {
        expect($channel->fresh())->is_active->toBeFalse()->auto_disabled->toBeTrue()->stream_status->toBe('offline');
    }
});

it('switches a channel back on when its stream works again', function () {
    fakeStreams();
    $channel = streamChannel('http://live.test/a.m3u8', ['is_active' => false, 'auto_disabled' => true, 'stream_status' => 'offline']);

    app(StreamCheckService::class)->checkChannels([$channel->id]);

    expect($channel->fresh())->is_active->toBeTrue()->auto_disabled->toBeFalse()->stream_status->toBe('online');
});

it('leaves channels an admin disabled alone', function () {
    fakeStreams();
    $channel = streamChannel('http://live.test/a.m3u8', ['is_active' => false]);

    app(StreamCheckService::class)->checkChannels([$channel->id]);

    expect($channel->fresh())->is_active->toBeFalse()->stream_status->toBeNull();
    Http::assertNothingSent();
});

it('queues the check in chunks, optionally for one source', function () {
    Queue::fake();
    $source = Source::create(['name' => 'P', 'type' => 'url', 'url' => 'http://p.test']);
    streamChannel('http://live.test/1', ['source_id' => $source->id]);
    streamChannel('http://live.test/2');
    streamChannel('http://live.test/3', ['is_active' => false]);

    expect(app(StreamCheckService::class)->queueCheck())->toBe(2)
        ->and(app(StreamCheckService::class)->queueCheck($source->id))->toBe(1);
    Queue::assertPushed(CheckStreams::class, 2);
});

it('runs inline from the command', function () {
    fakeStreams();
    streamChannel('http://live.test/a.m3u8');
    $dead = streamChannel('http://gone.test/a.m3u8');

    $this->artisan('iptv:check-streams', ['--now' => true])->expectsOutputToContain('Online: 1, offline: 1.')->assertSuccessful();

    expect($dead->fresh()->is_active)->toBeFalse();
});

it('is scheduled daily, and every 15 minutes for watched channels', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'iptv:check-streams'))
        ->mapWithKeys(fn ($e) => [trim(\Illuminate\Support\Str::after($e->command, 'iptv:check-streams')) => $e->expression]);

    expect($events->all())->toBe(['' => '0 4 * * *', '--watched' => '*/15 * * * *']);
});

describe('admin', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('queues a check from the channels page', function () {
        Queue::fake();
        streamChannel('http://live.test/a.m3u8');

        $this->from(route('admin.channels.index'))
            ->post(route('admin.channels.check-streams'))
            ->assertRedirect(route('admin.channels.index'))
            ->assertSessionHas('success', 'Checking 1 channel. If it does not work, it will be disabled.');
        Queue::assertPushed(CheckStreams::class);
    });

    it('shows and filters channels that do not work', function () {
        streamChannel('http://ok.test/a', ['name' => 'Fine Channel']);
        streamChannel('http://bad.test/a', ['name' => 'Dead Channel', 'is_active' => false, 'auto_disabled' => true, 'stream_checked_at' => now()]);

        $this->get(route('admin.channels.index', ['status' => 'offline']))
            ->assertOk()->assertSee('Dead Channel')->assertSee('Not working')->assertDontSee('Fine Channel');
    });

    it('hands a channel back to the admin when it is enabled by hand', function () {
        $channel = streamChannel('http://bad.test/a', ['is_active' => false, 'auto_disabled' => true]);

        $this->post(route('admin.channels.bulk'), ['action' => 'enable', 'scope' => 'selected', 'ids' => [$channel->id]]);

        expect($channel->fresh())->is_active->toBeTrue()->auto_disabled->toBeFalse();
    });

    it('clears the check flag when the edit form changes the status', function () {
        $channel = streamChannel('http://bad.test/a', ['name' => 'X', 'is_active' => false, 'auto_disabled' => true]);

        $this->put(route('admin.channels.update', $channel), ['name' => 'X', 'stream_url' => 'http://bad.test/a', 'is_active' => '1']);

        expect($channel->fresh())->is_active->toBeTrue()->auto_disabled->toBeFalse();
    });
});

it('tries failed streams once more before switching them off', function () {
    $calls = 0;
    Http::fake(['flaky.test/*' => function () use (&$calls) {
        return ++$calls === 1 ? Http::response('', 503) : Http::response("#EXTM3U\n", 200);
    }]);
    $channel = streamChannel('http://flaky.test/a.m3u8');

    app(StreamCheckService::class)->checkChannels([$channel->id]);

    expect($channel->fresh())->is_active->toBeTrue()->stream_status->toBe('online')->stream_error->toBeNull()
        ->and($calls)->toBe(2);
    \Illuminate\Support\Sleep::assertSlept(fn ($duration) => $duration->totalSeconds == StreamCheckService::RETRY_DELAY, 1);
});

it('does not retry or wait when everything works', function () {
    fakeStreams();
    $channel = streamChannel('http://live.test/a.m3u8');

    app(StreamCheckService::class)->checkChannels([$channel->id]);

    \Illuminate\Support\Sleep::assertNeverSlept();
});

it('stores why a stream failed', function () {
    fakeStreams();
    $gone = streamChannel('http://gone.test/a.m3u8');
    $html = streamChannel('http://html.test/a.m3u8');
    $down = streamChannel('http://down.test/a.m3u8');

    app(StreamCheckService::class)->checkChannels(Channel::pluck('id')->all());

    expect($gone->fresh()->stream_error)->toBe('http:404')
        ->and($html->fresh()->stream_error)->toBe('html')
        ->and($down->fresh()->stream_error)->toBe('connect')
        ->and(StreamCheckService::describeError('http:403'))->toBe('Refused or blocked in this country (HTTP 403)')
        ->and(StreamCheckService::describeError('timeout'))->toBe('No answer in time');
});

it('sends the user agent and referrer a stream needs', function () {
    fakeStreams();
    $channel = streamChannel('http://live.test/a.m3u8', ['http_user_agent' => 'Agent/9', 'http_referrer' => 'https://site.test/']);

    app(StreamCheckService::class)->checkChannels([$channel->id]);

    Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'Agent/9') && $request->hasHeader('Referer', 'https://site.test/'));
});
