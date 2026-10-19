<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Keeps the channel lists that apps download ready on disk, so a login does not build a list of thousands of channels from the
 * database every time: the Xtream channel list (all categories) and the M3U playlist (with a placeholder where each viewer's
 * play token goes).
 *
 * Viewers who see the same channels share one file: the key is their bouquets, their reseller's hidden channels and their own
 * hidden channels. A file is made again when channels, categories, bouquets, hidden channels or playlist options change (a
 * cheap check of counts and last-change times on every request, bump() for changes it can't see), and at least every MAX_AGE
 * seconds. Favourites are per viewer and are always added live.
 */
class ListCacheService
{
    public const MAX_AGE = 600;

    public const TOKEN_PLACEHOLDER = '__NEBOTV_TOKEN__';

    private const DIR = 'framework/cache/lists';

    /** Seconds the change check itself is remembered: many apps logging in at once share one check. */
    private const VERSION_SECONDS = 15;

    /**
     * Something changed that the change check does not see (numbers, better copies, playlist options): make all lists again.
     */
    public static function bump(): void
    {
        Cache::forever('channel-lists:version', (int) Cache::get('channel-lists:version', 0) + 1);
        Cache::forget('channel-lists:state');
    }

    /**
     * Path of a ready list for this viewer, made with $build (which gets a write callback) when missing or outdated.
     *
     * @param  string  $kind  "xtream" or "m3u"
     * @param  callable(callable(string): void): void  $build
     */
    public function path(User $user, string $kind, callable $build): string
    {
        $dir = $this->dir();
        $path = $dir.'/'.$kind.'-'.$this->key($user).'.cache';

        if (is_file($path) && filemtime($path) > time() - self::MAX_AGE) {
            return $path;
        }

        File::ensureDirectoryExists($dir);
        // Written next to it and moved in place, so a viewer never reads a half-written list
        $temp = $path.'.'.Str::random(8).'.tmp';
        $handle = fopen($temp, 'w');

        try {
            $build(function (string $chunk) use ($handle) {
                fwrite($handle, $chunk);
            });
        } finally {
            fclose($handle);
        }
        rename($temp, $path);

        return $path;
    }

    /** Delete lists nobody asked for in a day. */
    public function prune(): int
    {
        $deleted = 0;
        foreach (File::glob($this->dir().'/*') as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function dir(): string
    {
        return config('iptv.list_cache_dir') ?: storage_path(self::DIR);
    }

    /**
     * Who sees the same channels, plus the state of everything the list is made of.
     */
    private function key(User $user): string
    {
        $bouquets = $user->bouquets()->pluck('bouquets.id')->sort()->implode(',');
        $hidden = DB::table('user_hidden_channels')->where('user_id', $user->id)->selectRaw('COUNT(*) as n, COALESCE(SUM(channel_id), 0) as s')->first();
        $own = $hidden && $hidden->n > 0 ? $user->id.':'.$hidden->n.':'.$hidden->s : '-';

        // The playlist's play links are made with the address the app used (LAN IP or domain)
        return sha1(implode('|', [request()->getSchemeAndHttpHost(), $bouquets, $user->owner_id ?? 'admin', $own, $this->state()]));
    }

    private function state(): string
    {
        return Cache::remember('channel-lists:state', self::VERSION_SECONDS, function () {
            $row = DB::selectOne('SELECT
                (SELECT MAX(updated_at) FROM channels) AS channels_at, (SELECT COUNT(*) FROM channels) AS channels,
                (SELECT MAX(updated_at) FROM categories) AS categories_at, (SELECT COUNT(*) FROM categories) AS categories,
                (SELECT COUNT(*) FROM bouquet_channel) AS bouquet_channels, (SELECT COALESCE(SUM(channel_id), 0) FROM bouquet_channel) AS bouquet_sum,
                (SELECT COUNT(*) FROM owner_hidden_channels) AS owner_hidden, (SELECT COALESCE(SUM(channel_id), 0) FROM owner_hidden_channels) AS owner_hidden_sum,
                (SELECT MAX(updated_at) FROM app_settings) AS settings_at');

            return implode('|', [...array_values((array) $row), (int) Cache::get('channel-lists:version', 0)]);
        });
    }
}
