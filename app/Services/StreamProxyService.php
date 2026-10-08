<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Source;
use App\Models\User;
use App\Support\PublicAddress;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plays a channel through this server instead of sending the viewer to the provider, so the
 * provider address (and the login inside it) is never visible, stream headers the provider
 * needs are always sent, and the viewing session stays alive while data flows.
 *
 * Only for sources with `proxy_streams` on, and only when STREAM_PROXY is not `off`:
 * - `php`: PHP passes the stream on. Works on any host, but every viewer of a live MPEG-TS
 *   stream keeps one PHP worker busy.
 * - `nginx`: PHP checks access, then nginx passes the stream on (X-Accel-Redirect, see
 *   docker/nginx/stream-proxy.conf). No PHP worker is held, but no session heartbeat either.
 * HLS playlists always go through PHP, which rewrites them so segments are proxied too.
 */
class StreamProxyService
{
    public const MODES = ['off', 'php', 'nginx'];

    /** Hours a link to a part (segment, key) of a stream stays valid. */
    private const PART_LINK_HOURS = 6;

    /** Longest a single viewing is passed on in php mode. */
    private const MAX_SECONDS = 86400;

    public function __construct(private StreamPreviewService $preview, private StreamService $streams) {}

    /**
     * Answer a play request: redirect to the provider, or pass the stream on.
     * $streamUrl is what StreamService::getStreamUrl() chose (the playing channel's address).
     */
    public function respond(Request $request, User $user, Channel $channel, string $token, string $streamUrl): Response
    {
        if (!$this->shouldProxy($channel->playbackChannel(), $streamUrl)) {
            return redirect()->away($streamUrl);
        }

        return $this->pass($request, $user, $channel, $token, $streamUrl);
    }

    /**
     * Answer a request for a signed part (segment, key, sub-playlist) of a proxied stream.
     */
    public function respondPart(Request $request, User $user, Channel $channel, string $token, string $target): Response
    {
        $this->streams->touch($user, $channel);

        return $this->pass($request, $user, $channel, $token, $target);
    }

    /**
     * The address of a signed part of $channel's stream, as written into rewritten playlists.
     */
    public function partUrl(Channel $channel, string $token, string $target, int $userId): string
    {
        // Encrypted, not just signed: the address of a segment can contain the provider login.
        // Made for one channel and one user, and only good for a while (playlists are fetched again all the time)
        return route('stream', [
            'channel_id' => $channel->id,
            'token' => $token,
            'u' => Crypt::encryptString(json_encode(['c' => $channel->id, 'u' => $userId, 'e' => now()->addHours(self::PART_LINK_HOURS)->timestamp, 't' => $target])),
        ], false);
    }

    /**
     * The provider address inside the `u` parameter of a part link, or null when it is not one
     * of ours (wrong key, altered, or made for another channel).
     */
    public function decodePart(Channel $channel, User $user, ?string $encrypted): ?string
    {
        try {
            $data = json_decode(Crypt::decryptString((string) $encrypted), true);
        } catch (DecryptException) {
            return null;
        }

        $valid = is_array($data)
            && ($data['c'] ?? null) === $channel->id
            && ($data['u'] ?? null) === $user->id
            && ($data['e'] ?? 0) >= now()->timestamp
            && is_string($data['t'] ?? null);

        return $valid ? $data['t'] : null;
    }

    public function shouldProxy(Channel $playing, string $streamUrl): bool
    {
        $source = $playing->source;

        return $this->mode() !== 'off'
            && $source instanceof Source
            && $source->proxy_streams
            && !$source->isReceiver()
            && preg_match('#^https?://#i', $streamUrl) === 1;
    }

    /**
     * The proxy mode in effect. `nginx` needs the nginx rules in place: they announce
     * themselves to PHP, and without them the proxy falls back to `php` rather than
     * leaking the address in a header.
     */
    public function mode(): string
    {
        $mode = config('iptv.stream_proxy');

        if ($mode === 'nginx' && !request()->server('STREAM_PROXY_ENGINE')) {
            return 'php';
        }

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    private function pass(Request $request, User $user, Channel $channel, string $token, string $target): Response
    {
        // A playlist must not be able to aim the proxy at this server's own network
        if (!PublicAddress::allows($target)) {
            return response('This stream can not be played.', 502, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // HEAD would open (and hold) the provider stream just to throw it away
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Content-Type' => $this->isPlaylist($target) ? 'application/vnd.apple.mpegurl' : 'video/mp2t', 'Cache-Control' => 'no-store']);
        }

        if ($this->mode() === 'nginx' && !$this->isPlaylist($target, $channel)) {
            $playing = $channel->playbackChannel();

            return response('', 200, array_filter([
                'X-Accel-Redirect' => '/_proxy',
                'X-Proxy-Target' => $target,
                'X-Proxy-User-Agent' => $playing->http_user_agent ?: 'VLC/3.0.20 LibVLC/3.0.20',
                'X-Proxy-Referer' => $playing->http_referrer,
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-store',
            ]));
        }

        $fetch = fn (callable $alive) => $this->preview->proxy(
            $channel,
            $target,
            fn (string $part) => $this->partUrl($channel, $token, $part, $user->id),
            function () use ($user, $channel, $alive) {
                // An admin disconnected this device: stop passing the stream on
                if ($this->streams->isBlocked($user)) {
                    return false;
                }

                $this->streams->touch($user, $channel);
                $alive();

                return true;
            },
            self::MAX_SECONDS,
            publicOnly: true,
        );

        // Playlists and segments are short requests; only a running stream (no m3u8 in the address) is counted
        return stripos($target, 'm3u8') === false
            ? $this->limitParallel($user, $request, $fetch)
            : $fetch(fn () => null);
    }

    /**
     * An HLS playlist must be rewritten by PHP. "m3u8" in the address says so; for other addresses
     * nginx mode asks the provider once (first bytes only) and remembers the answer for an hour.
     */
    private function isPlaylist(string $target, ?Channel $channel = null): bool
    {
        if (stripos($target, 'm3u8') !== false) {
            return true;
        }

        if ($channel === null || $this->mode() !== 'nginx') {
            return false;
        }

        return Cache::remember('proxy-playlist:'.sha1($target), 3600, fn () => $this->preview->answersWithPlaylist($channel, $target));
    }

    /**
     * At most `iptv.proxy_streams_per_device` live streams at once per device in php mode: each one
     * holds a PHP worker. The count is kept in the cache, refreshed by the session heartbeat, and
     * expires by itself if a worker dies without counting down.
     */
    private function limitParallel(User $user, Request $request, callable $start): Response
    {
        $key = 'proxy-streams:'.$user->id.':'.sha1($request->ip().'|'.$request->userAgent());
        Cache::add($key, 0, 120);

        if (Cache::increment($key) > config('iptv.proxy_streams_per_device')) {
            Cache::decrement($key);

            return response('Too many streams are playing on this device.', 429, ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '5']);
        }

        $release = fn () => Cache::get($key, 0) > 0 && Cache::decrement($key);
        $response = $start(fn () => Cache::put($key, max(1, (int) Cache::get($key, 1)), 120));

        if (!$response instanceof StreamedResponse) {
            $release();

            return $response;
        }

        // Same response, but counted down when the stream ends (also when the viewer leaves)
        return new StreamedResponse(function () use ($response, $release) {
            try {
                $response->sendContent();
            } finally {
                $release();
            }
        }, $response->getStatusCode(), $response->headers->all());
    }
}
