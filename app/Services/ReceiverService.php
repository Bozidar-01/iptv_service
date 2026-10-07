<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamAlert;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Satellite receivers (Enigma2, e.g. Octagon SF8008) reached through the NeboTV relay that
 * runs on the receiver (relay/nebotv-relay.py, docs/features/receiver.md).
 *
 * Imported receiver channels store the relay's unsigned link (http://relay/s/<ref>.ts). On play,
 * the link gets an expiry and an HMAC signature made with the source's relay_secret; the relay
 * refuses anything else. The signature format must match sign() in nebotv-relay.py.
 */
class ReceiverService
{
    /**
     * The URL a player is sent to: the stream URL itself, or a signed relay link.
     */
    public function playUrl(Channel $channel): string
    {
        $source = $channel->source;

        if (!$source?->isReceiver() || !preg_match('#/s/([A-Za-z0-9_-]+)\.ts$#', (string) parse_url($channel->stream_url, PHP_URL_PATH), $match)) {
            return $channel->stream_url;
        }

        $expires = now()->addMinutes((int) config('iptv.receiver_link_minutes'))->timestamp;
        $transcode = $source->transcode ? '1' : '0';

        return strtok($channel->stream_url, '?').'?'.http_build_query([
            'e' => $expires,
            'x' => $transcode,
            's' => self::sign((string) $source->relay_secret, $match[1], $expires, $transcode),
        ]);
    }

    public static function sign(string $secret, string $channel, int|string $expires, string $transcode): string
    {
        return substr(hash_hmac('sha256', "{$channel}|{$expires}|{$transcode}", $secret), 0, 32);
    }

    /**
     * Ask every receiver's relay whether it and the box answer, and alert on changes.
     * Run every 5 minutes (iptv:check-receivers).
     */
    public function checkAll(): void
    {
        Source::where('type', 'receiver')->get()->each(fn (Source $source) => $this->check($source));
    }

    public function check(Source $source): bool
    {
        try {
            $response = Http::timeout(10)->get(rtrim((string) $source->url, '/').'/status', ['key' => $source->relay_secret]);
            $online = $response->successful() && $response->json('ok') === true;
        } catch (Throwable) {
            $online = false;
        }

        $was = $source->receiver_online;
        $source->update(['receiver_online' => $online, 'receiver_checked_at' => now()]);

        if ($was !== null && $was !== $online) {
            StreamAlert::create([
                'type' => $online ? StreamAlert::TYPE_RECEIVER_UP : StreamAlert::TYPE_RECEIVER_DOWN,
                'source_id' => $source->id,
                'name' => $source->name,
            ]);
        } elseif ($was === null && !$online) {
            StreamAlert::create(['type' => StreamAlert::TYPE_RECEIVER_DOWN, 'source_id' => $source->id, 'name' => $source->name]);
        }

        return $online;
    }
}
