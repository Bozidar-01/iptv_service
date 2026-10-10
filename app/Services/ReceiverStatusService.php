<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Source;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What the relay on a satellite receiver says about itself right now: tuners in use, the satellite it is on, and the
 * latest refusals and streams without video (relay 1.2+). Read from its /status, kept 15 seconds so reloading the page
 * doesn't hammer a box on a home line.
 */
class ReceiverStatusService
{
    public const CACHE_SECONDS = 15;

    /**
     * @return array{online: bool, error: ?string, version: ?string, active_channels: int, active_tuners: ?int, max_streams: int, single_cable: ?bool, satellites: list<string>, events: list<array{when: int, kind: string, reason: string, channel: string}>, has_events: bool}
     */
    public function status(Source $source): array
    {
        return Cache::remember("receiver-status:{$source->id}", self::CACHE_SECONDS, fn () => $this->fetch($source));
    }

    private function fetch(Source $source): array
    {
        $empty = ['online' => false, 'error' => null, 'version' => null, 'active_channels' => 0, 'active_tuners' => null, 'max_streams' => 0, 'single_cable' => null, 'satellites' => [], 'events' => [], 'has_events' => false];

        try {
            $response = Http::timeout(8)->get(rtrim((string) $source->url, '/').'/status', ['key' => $source->relay_secret]);
        } catch (Throwable) {
            return ['error' => __('The relay did not answer. Is the receiver on and the port forwarded?')] + $empty;
        }

        if ($response->status() === 403) {
            return ['error' => __('The relay refused the secret. Check the secret of this source.')] + $empty;
        }
        if (!$response->successful() || !is_array($response->json())) {
            return ['error' => __('The relay answered with an error.')] + $empty;
        }

        $data = $response->json();
        $events = (array) ($data['recent_events'] ?? []);
        $names = $this->channelNames($source, array_column($events, 'ref'));

        return [
            'online' => ($data['ok'] ?? false) === true,
            'error' => ($data['ok'] ?? false) === true ? null : __('The relay is running, but the receiver does not answer.'),
            'version' => isset($data['version']) ? (string) $data['version'] : null,
            'active_channels' => (int) ($data['active_channels'] ?? 0),
            'active_tuners' => isset($data['active_tuners']) ? (int) $data['active_tuners'] : null,
            'max_streams' => (int) ($data['max_streams'] ?? 0),
            'single_cable' => isset($data['single_cable']) ? (bool) $data['single_cable'] : null,
            'satellites' => array_map([self::class, 'satelliteName'], (array) ($data['satellites'] ?? [])),
            'events' => array_values(array_map(fn ($e) => [
                'when' => (int) ($e['t'] ?? 0),
                'kind' => (string) ($e['kind'] ?? ''),
                'reason' => (string) ($e['reason'] ?? ''),
                'channel' => $names[$e['ref'] ?? ''] ?? (string) ($e['ref'] ?? ''),
            ], array_reverse($events))),
            'has_events' => array_key_exists('recent_events', $data),
        ];
    }

    /** @param  list<string>  $refs @return array<string, string> service reference => channel name */
    private function channelNames(Source $source, array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs)));

        return $refs === [] ? [] : Channel::where('source_id', $source->id)->whereIn('tvg_id', $refs)->pluck('name', 'tvg_id')->all();
    }

    /** "13.0 E", "19.2 E", "5.0 W" from an orbital position in tenths of a degree (west positions count down from 3600). */
    public static function satelliteName(int $position): string
    {
        return $position > 1800 ? number_format((3600 - $position) / 10, 1).'° W' : number_format($position / 10, 1).'° E';
    }
}
