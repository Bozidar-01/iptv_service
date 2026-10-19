<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Carbon;

/**
 * Catch-up (watch a programme that already started or ended), passed on from providers that offer it. NeboTV keeps no
 * recordings: it only turns "this channel, from this time, this long" into the provider's own archive address.
 *
 * What a provider offers comes from its playlist (`catchup`, `catchup-days`, `catchup-source`, or the older `tvg-rec` /
 * `timeshift`), stored on the channel at import. The styles:
 *  - xc: an Xtream Codes server: /timeshift/{user}/{pass}/{minutes}/{Y-m-d:H-i}/{id}.ts on the stream's own server;
 *  - shift: the stream address plus ?utc={start}&lutc={now};
 *  - append: the stream address plus catchup-source, with its placeholders filled in;
 *  - default: catchup-source is the whole address, with its placeholders filled in;
 *  - flussonic: archive-{start}-{duration}.m3u8 (HLS) or timeshift_abs-{start}.ts in place of the stream's file name.
 * Viewers' apps get catch-up links to this server (M3U: catchup-source, Xtream: tv_archive), which check access like /play.
 */
class CatchupService
{
    public const TYPES = ['xc', 'shift', 'append', 'default', 'flussonic'];

    /** Days of archive when the playlist says catch-up is there but not for how long. */
    public const DEFAULT_DAYS = 5;

    /** Longest programme that can be asked for. */
    public const MAX_SECONDS = 6 * 3600;

    /**
     * What an imported entry says about catch-up: [type, days, source], or nulls when it has none.
     *
     * @param  array<string, string>  $attributes  #EXTINF attributes, lower-case names
     * @return array{0: ?string, 1: ?int, 2: ?string}
     */
    public static function fromAttributes(array $attributes, string $streamUrl): array
    {
        $type = strtolower(trim($attributes['catchup'] ?? $attributes['catchup-type'] ?? ''));
        $days = (int) ($attributes['catchup-days'] ?? $attributes['tvg-rec'] ?? $attributes['timeshift'] ?? 0);
        $source = trim($attributes['catchup-source'] ?? '') ?: null;

        $type = match ($type) {
            'xc', 'xtream' => 'xc',
            'shift', 'timeshift' => 'shift',
            'append' => 'append',
            'default' => 'default',
            'flussonic', 'flussonic-hls', 'flussonic-ts', 'fs' => 'flussonic',
            default => null,
        };

        // Archive days without a style: an Xtream server's address says xc, else the common "shift"
        if ($type === null && $days > 0) {
            $type = self::xtreamParts($streamUrl) ? 'xc' : 'shift';
        }
        if ($type === null || (in_array($type, ['append', 'default'], true) && $source === null)) {
            return [null, null, null];
        }

        return [$type, $days > 0 ? min($days, 365) : self::DEFAULT_DAYS, $source ? mb_substr($source, 0, 2048) : null];
    }

    /**
     * The provider's archive address for a programme on this channel (the stream actually played: its backup when it plays one),
     * or null when the channel has no catch-up or the time is outside its archive.
     */
    public function providerUrl(Channel $channel, Carbon $start, int $seconds): ?string
    {
        $seconds = max(60, min(self::MAX_SECONDS, $seconds));
        $days = $channel->catchup_days ?: self::DEFAULT_DAYS;

        if (!$channel->catchup || $start->isFuture() || $start->lt(now()->subDays($days)->subHour())) {
            return null;
        }

        $url = $channel->stream_url;

        return match ($channel->catchup) {
            'xc' => $this->xtreamUrl($url, $start, $seconds),
            'shift' => $url.(str_contains($url, '?') ? '&' : '?').'utc='.$start->timestamp.'&lutc='.now()->timestamp,
            'append' => $url.self::fill((string) $channel->catchup_source, $start, $seconds),
            'default' => self::fill((string) $channel->catchup_source, $start, $seconds),
            'flussonic' => $this->flussonicUrl($url, $start, $seconds),
            default => null,
        };
    }

    /**
     * Fill in the placeholders apps use in catch-up addresses: {utc}/{start}/${start} (start, Unix time), {utcend}/{end}/${end},
     * {lutc}/{now}/${now}/${timestamp}/{timestamp} (now), {duration}/${duration} (seconds), {duration:60} (divided, e.g.
     * minutes), {offset:1} (seconds ago), {Y} {m} {d} {H} {M} {S} (start, UTC), and {utc:Y-m-d H:M:S} style formats.
     */
    public static function fill(string $template, Carbon $start, int $seconds): string
    {
        $start = $start->copy()->utc();
        $end = $start->copy()->addSeconds($seconds);
        $now = now()->utc();

        $template = preg_replace_callback('/\{(utc|start|utcend|end|lutc|now|timestamp):([^}]+)\}/', function ($m) use ($start, $end, $now) {
            $time = match ($m[1]) {
                'utc', 'start' => $start,
                'utcend', 'end' => $end,
                default => $now,
            };

            return $time->format(strtr($m[2], ['M' => 'i', 'S' => 's']));
        }, $template);

        $template = preg_replace_callback('/\{(duration|offset):(\d+)\}/', function ($m) use ($start, $seconds, $now) {
            $value = $m[1] === 'duration' ? $seconds : max(0, $now->timestamp - $start->timestamp);

            return (string) intdiv($value, max(1, (int) $m[2]));
        }, $template);

        return strtr($template, [
            '${start}' => $start->timestamp, '{utc}' => $start->timestamp, '{start}' => $start->timestamp,
            '${end}' => $end->timestamp, '{utcend}' => $end->timestamp, '{end}' => $end->timestamp,
            '${now}' => $now->timestamp, '${timestamp}' => $now->timestamp, '{lutc}' => $now->timestamp, '{now}' => $now->timestamp, '{timestamp}' => $now->timestamp,
            '${duration}' => $seconds, '{duration}' => $seconds, '${offset}' => max(0, $now->timestamp - $start->timestamp), '{offset}' => max(0, $now->timestamp - $start->timestamp),
            '{Y}' => $start->format('Y'), '{m}' => $start->format('m'), '{d}' => $start->format('d'),
            '{H}' => $start->format('H'), '{M}' => $start->format('i'), '{S}' => $start->format('s'),
        ]);
    }

    /**
     * The parts of an Xtream Codes stream address (http://server:port[/live]/user/pass/id[.ext]), or null.
     *
     * @return array{base: string, user: string, pass: string, id: string}|null
     */
    public static function xtreamParts(string $url): ?array
    {
        if (!preg_match('#^(https?://[^/]+)/(?:live/)?([^/]+)/([^/]+)/(\d+)(?:\.\w+)?$#i', $url, $m)) {
            return null;
        }

        return ['base' => $m[1], 'user' => $m[2], 'pass' => $m[3], 'id' => $m[4]];
    }

    private function xtreamUrl(string $url, Carbon $start, int $seconds): ?string
    {
        $parts = self::xtreamParts($url);
        if ($parts === null) {
            return null;
        }

        // Xtream servers read the start in their own time zone; most run on UTC
        return sprintf('%s/timeshift/%s/%s/%d/%s/%s.ts', $parts['base'], $parts['user'], $parts['pass'], (int) ceil($seconds / 60), $start->copy()->utc()->format('Y-m-d:H-i'), $parts['id']);
    }

    private function flussonicUrl(string $url, Carbon $start, int $seconds): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $file = basename($path);
        $replacement = match (true) {
            str_ends_with($file, '.m3u8') => 'archive-'.$start->timestamp.'-'.$seconds.'.m3u8',
            $file === 'mpegts' || str_ends_with($file, '.ts') => 'timeshift_abs-'.$start->timestamp.'.ts',
            default => null,
        };

        if ($replacement === null) {
            return null;
        }

        $newPath = substr($path, 0, strlen($path) - strlen($file)).$replacement;

        return str_replace($path, $newPath, $url);
    }
}
