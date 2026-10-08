<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Source;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

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

    /** Longest a single viewing is passed on in php mode. */
    private const MAX_SECONDS = 86400;

    public function __construct(private StreamPreviewService $preview, private StreamService $streams)
    {
    }

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
    public function partUrl(Channel $channel, string $token, string $target): string
    {
        // Encrypted, not just signed: the address of a segment can contain the provider login
        return route('stream', [
            'channel_id' => $channel->id,
            'token' => $token,
            'u' => Crypt::encryptString(json_encode(['c' => $channel->id, 't' => $target])),
        ], false);
    }

    /**
     * The provider address inside the `u` parameter of a part link, or null when it is not one
     * of ours (wrong key, altered, or made for another channel).
     */
    public function decodePart(Channel $channel, ?string $encrypted): ?string
    {
        try {
            $data = json_decode(Crypt::decryptString((string) $encrypted), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($data) && ($data['c'] ?? null) === $channel->id && is_string($data['t'] ?? null) ? $data['t'] : null;
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
        if ($this->mode() === 'nginx' && !$this->isPlaylist($target)) {
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

        return $this->preview->proxy(
            $channel,
            $target,
            fn (string $part) => $this->partUrl($channel, $token, $part),
            fn () => $this->streams->touch($user, $channel),
            self::MAX_SECONDS,
        );
    }

    private function isPlaylist(string $target): bool
    {
        return stripos($target, 'm3u8') !== false;
    }
}
