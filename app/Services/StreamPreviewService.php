<?php

namespace App\Services;

use App\Models\Channel;
use App\Support\PublicAddress;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lets admins watch a channel in the browser.
 *
 * The panel fetches the stream itself and passes it on, so provider streams without CORS
 * headers, or on plain http behind an https panel, still play. HLS playlists are rewritten
 * so their segments, keys and sub-playlists also go through the panel. Every proxied URL is
 * signed, so the endpoint can't be used to fetch arbitrary addresses.
 */
class StreamPreviewService
{
    private const USER_AGENT = 'VLC/3.0.20 LibVLC/3.0.20';

    private const CONNECT_TIMEOUT = 8;

    private const READ_TIMEOUT = 20;

    private const CHUNK = 16384;

    private const MAX_PLAYLIST_BYTES = 2 * 1024 * 1024;

    /** A live MPEG-TS stream never ends; stop passing it on after this many seconds. */
    public const MAX_SECONDS = 1800;

    /** Seconds between calls of the `onTick` callback of proxy(). */
    private const TICK_SECONDS = 30;

    /**
     * The panel URL that plays $target (default: the channel's stream) through the proxy.
     */
    public function url(Channel $channel, ?string $target = null): string
    {
        if ($target === null) {
            return route('admin.channels.preview', $channel, false);
        }

        return route('admin.channels.preview', [
            'channel' => $channel,
            'u' => $this->encode($target),
            's' => $this->sign($channel, $target),
        ], false);
    }

    /** The URL-safe form of an address, as put in the `u` parameter. */
    public function encode(string $target): string
    {
        return rtrim(strtr(base64_encode($target), '+/', '-_'), '=');
    }

    /**
     * The upstream URL a preview request asks for, or null when the signature is wrong.
     */
    public function target(Channel $channel, ?string $encoded, ?string $signature): ?string
    {
        if ($encoded === null) {
            return app(ReceiverService::class)->playUrl($channel->playbackChannel());
        }

        $target = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($target === false || !is_string($signature) || !hash_equals($this->sign($channel, $target), $signature)) {
            return null;
        }

        return $target;
    }

    /**
     * Fetch $target and pass it on: playlists rewritten, media streamed as it arrives.
     *
     * The upstream request sends the playing channel's own User-Agent / Referer when it has them.
     *
     * @param  ?callable(string): string  $urlFor  address a playlist entry is rewritten to (default: the admin preview)
     * @param  ?callable(): void  $onTick  called about every 30 seconds while media is passed on (keeps a viewing session alive)
     * @param  ?int  $maxSeconds  stop passing media on after this long (default MAX_SECONDS)
     * @param  bool  $publicOnly  refuse redirects to private / local addresses (viewers' streams, see PublicAddress)
     */
    public function proxy(Channel $channel, string $target, ?callable $urlFor = null, ?callable $onTick = null, ?int $maxSeconds = null, bool $publicOnly = false): Response
    {
        $maxSeconds ??= self::MAX_SECONDS;
        $playing = $channel->playbackChannel();

        if (!preg_match('#^https?://#i', $target)) {
            return $this->error(__('This stream address can not be played in the browser.'), 422);
        }

        try {
            $upstream = Http::withUserAgent($playing->http_user_agent ?: self::USER_AGENT)
                ->withHeaders($playing->http_referrer ? ['Referer' => $playing->http_referrer] : [])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(0)
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => self::READ_TIMEOUT,
                    'allow_redirects' => [
                        'max' => 5,
                        'track_redirects' => true,
                        'on_redirect' => function ($request, $response, $uri) use ($publicOnly) {
                            if ($publicOnly && !PublicAddress::allows((string) $uri)) {
                                throw new \RuntimeException('Redirect to a private address.');
                            }
                        },
                    ],
                ])
                ->get($target);
        } catch (\Throwable) {
            // Not just connection errors: too many redirects, a refused redirect... and none of their
            // messages may reach the viewer, they contain the provider address
            return $this->error(__('The provider did not answer.'));
        }

        if (!$upstream->successful()) {
            return $this->error(__('The provider answered with error :status.', ['status' => $upstream->status()]));
        }

        $body = $upstream->toPsrResponse()->getBody();
        $type = $upstream->header('Content-Type');
        $first = $this->readFirst($body);

        if ($this->isPlaylist($type, $first)) {
            $redirects = $upstream->toPsrResponse()->getHeader('X-Guzzle-Redirect-History');
            $base = $redirects ? end($redirects) : $target;

            try {
                $rewritten = $this->rewritePlaylist($channel, $first.$this->readRest($body), $base, $urlFor);
            } catch (\Throwable) {
                return $this->error(__('The provider sent a playlist this server can not read.'));
            }

            return response($rewritten, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-store',
            ]);
        }

        if (stripos($type, 'html') !== false || preg_match('/^\s*</', $first)) {
            $body->close();

            return $this->error(__('The provider sent a web page instead of a stream.'));
        }

        return new StreamedResponse(function () use ($body, $first, $maxSeconds, $onTick) {
            set_time_limit($maxSeconds + 60);
            $stopAt = time() + $maxSeconds;
            $nextTick = time() + self::TICK_SECONDS;

            $send = function (string $data) {
                echo $data;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $send($first);

            while (!$body->eof() && !connection_aborted() && time() < $stopAt) {
                $chunk = $body->read(self::CHUNK);
                if ($chunk !== '') {
                    $send($chunk);
                }

                if ($onTick && time() >= $nextTick) {
                    $onTick();
                    $nextTick = time() + self::TICK_SECONDS;
                }
            }

            $body->close();
        }, 200, [
            'Content-Type' => $type !== '' ? $type : 'video/mp2t',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Point every URI in an HLS playlist (lines and URI="…" attributes) at the proxy.
     */
    public function rewritePlaylist(Channel $channel, string $playlist, string $base, ?callable $urlFor = null): string
    {
        $baseUri = new Uri($base);
        $urlFor ??= fn (string $target) => $this->url($channel, $target);
        $proxy = fn (string $uri) => $urlFor((string) UriResolver::resolve($baseUri, new Uri(trim($uri))));

        $lines = preg_split('/\r\n|\n|\r/', $playlist);

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $lines[$i] = str_starts_with($trimmed, '#')
                ? preg_replace_callback('/URI="([^"]+)"/', fn ($m) => 'URI="'.$proxy($m[1]).'"', $line)
                : $proxy($trimmed);
        }

        return implode("\n", $lines);
    }

    private function isPlaylist(string $type, string $first): bool
    {
        return str_starts_with(ltrim($first, "\xEF\xBB\xBF \t\r\n"), '#EXTM3U')
            || (stripos($type, 'mpegurl') !== false && !preg_match('/^\s*</', $first));
    }

    /**
     * Read until there is enough to tell a playlist from media (or the body ends).
     */
    private function readFirst(StreamInterface $body): string
    {
        $data = '';

        while (strlen($data) < 512 && !$body->eof()) {
            $data .= $body->read(512 - strlen($data));
        }

        return $data;
    }

    private function readRest(StreamInterface $body): string
    {
        $data = '';

        while (!$body->eof() && strlen($data) < self::MAX_PLAYLIST_BYTES) {
            $data .= $body->read(self::CHUNK);
        }

        $body->close();

        return $data;
    }

    private function sign(Channel $channel, string $target): string
    {
        return substr(hash_hmac('sha256', $channel->id.'|'.$target, config('app.key')), 0, 32);
    }

    private function error(string $message, int $status = 502): Response
    {
        return response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
