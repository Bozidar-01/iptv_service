<?php

use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\StreamProxyService;
use App\Services\StreamService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** A subscribed channel on a source that hides the provider address. */
function proxiedChannel(User $user, string $url = 'http://provider.test/live/u/secret/1.ts', array $channel = [], array $source = []): Channel
{
    $src = Source::create(array_merge(['name' => 'Provider', 'type' => 'url', 'url' => 'http://provider.test/list.m3u', 'proxy_streams' => true], $source));

    return subscribedChannel($user, array_merge(['name' => 'Proxied', 'stream_url' => $url, 'source_id' => $src->id], $channel));
}

function playUrlFor(User $user, Channel $channel): string
{
    return route('play', ['channel_id' => $channel->id, 'token' => tokenFor($user)]);
}

// Fake hostnames (provider.test) may resolve to anything on a test machine: the address guard has its own tests below
beforeEach(fn () => config(['iptv.proxy_allow_private' => true]));

describe('php mode', function () {
    beforeEach(fn () => config(['iptv.stream_proxy' => 'php']));

    it('passes the stream on without revealing the provider', function () {
        Http::fake(['provider.test/*' => Http::response(str_repeat("\x47", 3000), 200, ['Content-Type' => 'video/mp2t'])]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user, channel: ['http_user_agent' => 'Picky/1.0', 'http_referrer' => 'http://ref.test/']);

        $response = $this->get(playUrlFor($user, $channel))->assertOk()->assertHeader('Content-Type', 'video/mp2t');

        expect($response->streamedContent())->toBe(str_repeat("\x47", 3000))
            ->and($response->headers->has('Location'))->toBeFalse()
            ->and(StreamLog::where('channel_id', $channel->id)->count())->toBe(1)
            ->and(ActiveSession::where('user_id', $user->id)->count())->toBe(1);
        Http::assertSent(fn ($request) => $request->header('User-Agent')[0] === 'Picky/1.0' && $request->header('Referer')[0] === 'http://ref.test/');
    });

    it('still redirects for sources that did not turn it on', function () {
        $user = User::factory()->create();
        $channel = proxiedChannel($user, source: ['proxy_streams' => false]);

        $this->get(playUrlFor($user, $channel))->assertRedirect($channel->stream_url);
    });

    it('never proxies satellite receiver channels', function () {
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://box.test:8080/s/abc.ts', source: ['type' => 'receiver', 'url' => 'http://box.test:8080', 'relay_secret' => 'k']);

        $this->get(playUrlFor($user, $channel))->assertRedirectContains('http://box.test:8080/s/abc.ts?');
    });

    it('proxies the Xtream live address the same way', function () {
        Http::fake(['provider.test/*' => Http::response('ts-bytes', 200, ['Content-Type' => 'video/mp2t'])]);
        $user = User::factory()->create(['username' => 'tv1', 'password' => bcrypt('secret')]);
        $channel = proxiedChannel($user);

        $response = $this->get("/live/tv1/secret/{$channel->id}.ts")->assertOk();

        expect($response->streamedContent())->toBe('ts-bytes');
    });

    it('keeps the access rules: token, bouquet and connection limit', function () {
        Http::fake(['provider.test/*' => Http::response('ts-bytes')]);
        $user = User::factory()->create(['max_connections' => 1]);
        $channel = proxiedChannel($user);
        $stranger = User::factory()->create();

        $this->get(route('play', ['channel_id' => $channel->id, 'token' => 'nope']))->assertStatus(401);
        $this->get(playUrlFor($stranger, $channel))->assertStatus(403);

        // Another device is already watching: the only slot is taken
        ActiveSession::create(['user_id' => $user->id, 'channel_id' => $channel->id, 'ip' => '10.9.9.9', 'user_agent' => 'Other TV', 'started_at' => now(), 'last_activity_at' => now()]);
        $this->get(playUrlFor($user, $channel))->assertStatus(403);
    });

    it('rewrites HLS playlists so every part goes through the server and hides the provider', function () {
        Http::fake([
            'provider.test/hls/index.m3u8' => Http::response("#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key.bin?k=SECRETKEY\"\n#EXTINF:6,\nseg1.ts?token=PROVIDERLOGIN\n", 200, ['Content-Type' => 'application/vnd.apple.mpegurl']),
            'provider.test/hls/seg1.ts*' => Http::response('segment-bytes', 200, ['Content-Type' => 'video/mp2t']),
        ]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');

        $playlist = $this->get(playUrlFor($user, $channel))->assertOk()->getContent();

        expect($playlist)->not->toContain('provider.test')->not->toContain('PROVIDERLOGIN')->not->toContain('SECRETKEY')
            ->and($playlist)->not->toContain(base64_encode('http://provider.test/hls/seg1.ts?token=PROVIDERLOGIN'))
            ->and($playlist)->toContain('/stream/'.$channel->id.'/');

        preg_match('~^(/stream/\S+)$~m', $playlist, $match);
        $response = $this->get($match[1])->assertOk();

        expect($response->streamedContent())->toBe('segment-bytes');
        Http::assertSent(fn ($request) => $request->url() === 'http://provider.test/hls/seg1.ts?token=PROVIDERLOGIN');
    });

    it('refuses altered, foreign or unauthorised part links', function () {
        Http::fake(['provider.test/*' => Http::response('x')]);
        $user = User::factory()->create(['max_connections' => 2]);
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');
        $other = proxiedChannel($user, 'http://provider.test/hls/other.m3u8');
        $token = tokenFor($user);

        $part = app(StreamProxyService::class)->partUrl($channel, $token, 'http://provider.test/hls/seg.ts', $user->id);
        $this->get($part)->assertOk();

        // Flip one character in the middle of the encrypted address
        $at = strpos($part, 'u=') + 30;
        $this->get(substr($part, 0, $at).($part[$at] === 'A' ? 'B' : 'A').substr($part, $at + 1))->assertStatus(403);
        $this->get(str_replace('/stream/'.$channel->id.'/', '/stream/'.$other->id.'/', $part))->assertStatus(403);
        $this->get(str_replace($token, 'nope', $part))->assertStatus(401);
        $this->get(route('stream', ['channel_id' => $channel->id, 'token' => $token]))->assertStatus(403);

        $stranger = User::factory()->create();
        $this->get(str_replace($token, tokenFor($stranger), $part))->assertStatus(403);
    });

    it('keeps the viewing alive for every part that is fetched', function () {
        Http::fake(['provider.test/*' => Http::response('x')]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');
        $this->get(playUrlFor($user, $channel))->assertOk();

        $this->travel(4)->minutes();
        $part = app(StreamProxyService::class)->partUrl($channel, tokenFor($user), 'http://provider.test/hls/seg.ts', $user->id);
        $this->get($part)->assertOk();

        expect(ActiveSession::where('user_id', $user->id)->sole()->last_activity_at->diffInSeconds(now()))->toBeLessThan(5)
            // and only the first request is a new play
            ->and(StreamLog::where('user_id', $user->id)->count())->toBe(1);
    });

    it('reports a provider that does not answer', function () {
        Http::fake(['provider.test/*' => Http::response('', 500)]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user);

        $this->get(playUrlFor($user, $channel))->assertStatus(502);
    });

    it('limits parts per link separately from plays', function () {
        Http::fake(['provider.test/*' => Http::response('x')]);
        config(['iptv.throttle_stream_per_token' => 2]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');
        $part = app(StreamProxyService::class)->partUrl($channel, tokenFor($user), 'http://provider.test/hls/seg.ts', $user->id);

        $this->get($part)->assertOk();
        $this->get($part)->assertOk();
        $this->get($part)->assertStatus(429);
    });
});

describe('nginx mode', function () {
    beforeEach(fn () => config(['iptv.stream_proxy' => 'nginx']));

    it('hands media streams to nginx', function () {
        Http::fake();
        $user = User::factory()->create();
        $channel = proxiedChannel($user, channel: ['http_user_agent' => 'Picky/1.0']);

        $this->withServerVariables(['STREAM_PROXY_ENGINE' => 'nginx'])
            ->get(playUrlFor($user, $channel))
            ->assertOk()
            ->assertHeader('X-Accel-Redirect', '/_proxy')
            ->assertHeader('X-Proxy-Target', $channel->stream_url)
            ->assertHeader('X-Proxy-User-Agent', 'Picky/1.0');

        // PHP never fetched the stream itself
        Http::assertNothingSent();
        expect(StreamLog::count())->toBe(1);
    });

    it('still rewrites HLS playlists in PHP', function () {
        Http::fake(['provider.test/*' => Http::response("#EXTM3U\n#EXTINF:6,\nseg1.ts\n", 200, ['Content-Type' => 'application/vnd.apple.mpegurl'])]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');

        $response = $this->withServerVariables(['STREAM_PROXY_ENGINE' => 'nginx'])->get(playUrlFor($user, $channel))->assertOk();

        expect($response->headers->has('X-Accel-Redirect'))->toBeFalse()
            ->and($response->getContent())->toContain('/stream/'.$channel->id.'/');
    });

    it('hands HLS segments to nginx too', function () {
        Http::fake();
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');
        $part = app(StreamProxyService::class)->partUrl($channel, tokenFor($user), 'http://provider.test/hls/seg1.ts', $user->id);

        $this->withServerVariables(['STREAM_PROXY_ENGINE' => 'nginx'])->get($part)
            ->assertHeader('X-Accel-Redirect', '/_proxy')
            ->assertHeader('X-Proxy-Target', 'http://provider.test/hls/seg1.ts');
    });

    it('falls back to PHP when the nginx rules are not installed, instead of leaking the address in a header', function () {
        Http::fake(['provider.test/*' => Http::response('ts-bytes', 200, ['Content-Type' => 'video/mp2t'])]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user);

        $response = $this->get(playUrlFor($user, $channel))->assertOk();

        expect($response->headers->has('X-Accel-Redirect'))->toBeFalse()
            ->and($response->headers->has('X-Proxy-Target'))->toBeFalse()
            ->and($response->streamedContent())->toBe('ts-bytes');
    });
});

it('does nothing while the proxy is switched off for the whole server', function () {
    config(['iptv.stream_proxy' => 'off']);
    $user = User::factory()->create();
    $channel = proxiedChannel($user);

    $this->get(playUrlFor($user, $channel))->assertRedirect($channel->stream_url);
});

it('lets StreamService refresh a session without logging a new play', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user);
    $service = app(StreamService::class);

    $service->touch($user, $channel);
    $this->travel(2)->minutes();
    $service->touch($user, $channel);

    expect(ActiveSession::where('user_id', $user->id)->sole()->last_activity_at->diffInSeconds(now()))->toBeLessThan(2)
        ->and(StreamLog::count())->toBe(0);
});

it('saves "Hide provider address" on a source, but never for receivers', function () {
    $this->actingAs(User::factory()->admin()->create());
    Queue::fake();

    $this->post(route('admin.sources.store'), ['name' => 'P', 'type' => 'url', 'url' => 'http://p.test/list.m3u', 'proxy_streams' => '1'])->assertSessionHasNoErrors()->assertRedirect();
    expect(Source::where('name', 'P')->sole()->proxy_streams)->toBeTrue();

    $this->post(route('admin.sources.store'), ['name' => 'R', 'type' => 'receiver', 'relay_url' => 'http://box.test:8080', 'relay_secret' => 'a-long-secret-key-123', 'proxy_streams' => '1'])->assertSessionHasNoErrors()->assertRedirect();
    expect(Source::where('name', 'R')->sole()->proxy_streams)->toBeFalse();

    $this->get(route('admin.sources.create'))->assertOk()->assertSee('Hide provider address');
});

describe('hardening', function () {
    beforeEach(fn () => config(['iptv.stream_proxy' => 'php']));

    it('does not fetch from the server\'s own network', function (string $url) {
        config(['iptv.proxy_allow_private' => false]);
        Http::fake();
        $user = User::factory()->create();
        $channel = proxiedChannel($user, $url);

        $this->get(playUrlFor($user, $channel))->assertStatus(502);

        Http::assertNothingSent();
    })->with([
        'loopback' => 'http://127.0.0.1:3306/x.ts',
        'localhost' => 'http://localhost/x.ts',
        'private' => 'http://192.168.1.5/live.ts',
        'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
        'ipv6 loopback' => 'http://[::1]/x.ts',
    ]);

    it('allows private addresses when the admin says so', function () {
        Http::fake(['*' => Http::response('lan-bytes', 200, ['Content-Type' => 'video/mp2t'])]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://192.168.1.5/live.ts');

        expect($this->get(playUrlFor($user, $channel))->assertOk()->streamedContent())->toBe('lan-bytes');
    });

    it('does not open the provider stream for a HEAD request', function () {
        Http::fake();
        $user = User::factory()->create();
        $channel = proxiedChannel($user);

        $this->call('HEAD', playUrlFor($user, $channel))->assertOk();

        Http::assertNothingSent();
    });

    it('never shows an exception message that contains the provider address', function () {
        Http::fake(['provider.test/*' => fn () => throw new RuntimeException('Too many redirects for http://provider.test/live/bob/hunter2/1.ts')]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user);

        $response = $this->get(playUrlFor($user, $channel))->assertStatus(502);

        expect($response->getContent())->not->toContain('hunter2')->not->toContain('provider.test');
    });

    it('refuses a part link after it has expired', function () {
        Http::fake(['provider.test/*' => Http::response('x')]);
        $user = User::factory()->create();
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');
        $token = tokenFor($user);
        $part = app(StreamProxyService::class)->partUrl($channel, $token, 'http://provider.test/hls/seg.ts', $user->id);

        $this->get($part)->assertOk();
        $this->travel(7)->hours();
        $this->get($part)->assertStatus(403);
    });

    it('refuses a part link made for another user', function () {
        Http::fake(['provider.test/*' => Http::response('x')]);
        $user = User::factory()->create(['max_connections' => 2]);
        $other = User::factory()->create(['max_connections' => 2]);
        $channel = proxiedChannel($user, 'http://provider.test/hls/index.m3u8');
        $other->bouquets()->attach($user->bouquets()->first());

        $forOther = app(StreamProxyService::class)->partUrl($channel, tokenFor($user), 'http://provider.test/hls/seg.ts', $other->id);

        $this->get($forOther)->assertStatus(403);
    });

    it('limits starting streams through Xtream /live like /play', function () {
        Http::fake(['provider.test/*' => Http::response('x', 200, ['Content-Type' => 'video/mp2t'])]);
        config(['iptv.throttle_per_token' => 2]);
        $user = User::factory()->create(['username' => 'tv1', 'password' => bcrypt('secret')]);
        $channel = proxiedChannel($user);

        $this->get("/live/tv1/secret/{$channel->id}.ts")->assertOk();
        $this->get("/live/tv1/secret/{$channel->id}.ts")->assertOk();
        $this->get("/live/tv1/secret/{$channel->id}.ts")->assertStatus(429);
    });
});
