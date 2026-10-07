<?php

use App\Models\Channel;
use App\Models\User;
use App\Services\StreamPreviewService;
use Illuminate\Support\Facades\Http;

function previewChannel(string $url): Channel
{
    return Channel::create(['name' => 'Preview', 'stream_url' => $url, 'is_active' => true]);
}

it('requires an admin to watch a channel', function () {
    $channel = previewChannel('http://ts.test/live/1.ts');

    $this->get(route('admin.channels.preview', $channel))->assertRedirect(route('login'));
});

describe('as admin', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('shows a watch button for each channel', function () {
        $channel = previewChannel('http://ts.test/live/1.ts');

        $this->get(route('admin.channels.index'))
            ->assertOk()
            ->assertSee('data-watch="'.route('admin.channels.preview', $channel, false).'"', false)
            ->assertSee('player-dialog');
    });

    it('passes an MPEG-TS stream through', function () {
        Http::fake(['ts.test/*' => Http::response(str_repeat("\x47", 2000), 200, ['Content-Type' => 'video/mp2t'])]);
        $channel = previewChannel('http://ts.test/live/1.ts');

        $response = $this->get(route('admin.channels.preview', $channel));

        $response->assertOk()->assertHeader('Content-Type', 'video/mp2t')->assertHeader('X-Accel-Buffering', 'no');
        expect($response->streamedContent())->toBe(str_repeat("\x47", 2000));
        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0], 'VLC'));
    });

    it('rewrites an HLS playlist so segments, keys and variants go through the panel', function () {
        Http::fake(['live.test/*' => Http::response(
            "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key.bin\"\n#EXTINF:6,\nseg1.ts\n#EXTINF:6,\n/abs/seg2.ts?x=1\n#EXTINF:6,\nhttp://cdn.test/seg3.ts\n",
            200,
            ['Content-Type' => 'application/vnd.apple.mpegurl'],
        )]);
        $channel = previewChannel('http://live.test/hls/index.m3u8');
        $service = app(StreamPreviewService::class);

        $body = $this->get(route('admin.channels.preview', $channel))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')
            ->getContent();

        expect($body)
            ->toContain('URI="'.$service->url($channel, 'http://live.test/hls/key.bin').'"')
            ->toContain($service->url($channel, 'http://live.test/hls/seg1.ts'))
            ->toContain($service->url($channel, 'http://live.test/abs/seg2.ts?x=1'))
            ->toContain($service->url($channel, 'http://cdn.test/seg3.ts'))
            ->toContain('#EXTINF:6,')
            ->not->toContain("\nseg1.ts");
    });

    it('fetches signed parts of a playlist', function () {
        Http::fake(['cdn.test/*' => Http::response('segment-bytes', 200, ['Content-Type' => 'video/mp2t'])]);
        $channel = previewChannel('http://live.test/hls/index.m3u8');
        $url = app(StreamPreviewService::class)->url($channel, 'http://cdn.test/seg3.ts');

        $response = $this->get($url)->assertOk();

        expect($response->streamedContent())->toBe('segment-bytes');
        Http::assertSent(fn ($request) => $request->url() === 'http://cdn.test/seg3.ts');
    });

    it('refuses unsigned or tampered addresses', function () {
        Http::fake();
        $channel = previewChannel('http://live.test/hls/index.m3u8');
        $other = previewChannel('http://other.test/index.m3u8');
        $signed = app(StreamPreviewService::class)->url($channel, 'http://cdn.test/seg.ts');
        parse_str(parse_url($signed, PHP_URL_QUERY), $query);
        $evil = rtrim(strtr(base64_encode('http://169.254.169.254/latest'), '+/', '-_'), '=');

        $this->get(route('admin.channels.preview', [$channel, 'u' => $evil]))->assertForbidden();
        $this->get(route('admin.channels.preview', [$channel, 'u' => $evil, 's' => $query['s']]))->assertForbidden();
        $this->get(route('admin.channels.preview', [$other, 'u' => $query['u'], 's' => $query['s']]))->assertForbidden();
        Http::assertNothingSent();
    });

    it('explains why a stream does not play', function (array $fake, string $message) {
        Http::fake(['bad.test/*' => $fake[0]]);
        $channel = previewChannel('http://bad.test/live/1.ts');

        $this->get(route('admin.channels.preview', $channel))
            ->assertStatus(502)
            ->assertSee($message);
    })->with([
        'not found' => [fn () => [Http::response('nope', 404)], 'The provider answered with error 404.'],
        'web page' => [fn () => [Http::response('<html>Blocked</html>', 200, ['Content-Type' => 'text/html'])], 'The provider sent a web page instead of a stream.'],
        'down' => [fn () => [Http::failedConnection()], 'The provider did not answer.'],
    ]);

    it('does not proxy non-http addresses', function () {
        Http::fake();
        $channel = previewChannel('rtmp://live.test/stream');

        $this->get(route('admin.channels.preview', $channel))->assertStatus(422);
        Http::assertNothingSent();
    });
});
