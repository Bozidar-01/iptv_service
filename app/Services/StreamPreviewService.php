<?php

namespace App\Services;

use App\Models\Channel;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
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
            'u' => rtrim(strtr(base64_encode($target), '+/', '-_'), '='),
            's' => $this->sign($channel, $target),
        ], false);
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
     */
    public function proxy(Channel $channel, string $target): Response
    {
        if (!preg_match('#^https?://#i', $target)) {
            return $this->error(__('This stream address can not be played in the browser.'), 422);
        }

        try {
            $upstream = Http::withUserAgent(self::USER_AGENT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(0)
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => self::READ_TIMEOUT,
                    'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                ])
                ->get($target);
        } catch (ConnectionException) {
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

            return response($this->rewritePlaylist($channel, $first.$this->readRest($body), $base), 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-store',
            ]);
        }

        if (stripos($type, 'html') !== false || preg_match('/^\s*</', $first)) {
            $body->close();

            return $this->error(__('The provider sent a web page instead of a stream.'));
        }

        return new StreamedResponse(function () use ($body, $first) {
            set_time_limit(self::MAX_SECONDS + 60);
            $stopAt = time() + self::MAX_SECONDS;

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
    public function rewritePlaylist(Channel $channel, string $playlist, string $base): string
    {
        $baseUri = new Uri($base);
        $proxy = fn (string $uri) => $this->url($channel, (string) UriResolver::resolve($baseUri, new Uri(trim($uri))));

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
