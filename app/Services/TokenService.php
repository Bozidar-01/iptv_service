<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TokenService
{
    public function generateToken(User $user)
    {
        return AccessToken::create([
            'user_id' => $user->id,
            'token' => Str::random(32),
            'expires_at' => now()->addMonths(1), // Default 1 month
        ]);
    }

    /**
     * The token part of a play link: "{token}_{device key}" plus a stream extension, or just "{token}" in older links and in
     * links made outside a playlist.
     *
     * @return array{0: string, 1: ?string} [token, device key]
     */
    public static function parsePlayToken(string $value): array
    {
        $value = Str::before($value, '.');
        [$token, $device] = array_pad(explode('_', $value, 2), 2, null);

        return [$token, $device !== null && preg_match('/^[0-9a-z]{8,16}$/', $device) ? $device : null];
    }

    /**
     * A new device key, for the play links of one playlist download: when it was made (base 36 seconds) and 5 random characters.
     * Each app that loads the playlist gets its own, so two identical TVs behind one router count as two devices.
     */
    public static function newDeviceKey(): string
    {
        return base_convert((string) now()->timestamp, 10, 36).Str::lower(Str::random(5));
    }

    public static function deviceKeyIssuedAt(string $key): Carbon
    {
        return Carbon::createFromTimestamp((int) base_convert(substr($key, 0, -5), 36, 10));
    }

    public function validateToken(string $token)
    {
        return AccessToken::where('token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Token embedded in play URLs. Reused while it has more than a week left, so a
     * playlist cached by a TV app keeps working until the app's next refresh.
     */
    public function playbackToken(User $user): AccessToken
    {
        return $user->accessTokens()->where('expires_at', '>', now()->addWeek())->first()
            ?? $this->generateToken($user);
    }

    /**
     * The whole playlist as a string. Fine for tests and small lists; the controller streams it
     * with writeM3u() instead, so a 100k-channel playlist never sits in memory.
     */
    public function getM3u(User $user, string $tokenValue, ?string $guideUrl = null): string
    {
        $m3u = '';
        $this->writeM3u($user, $tokenValue, $guideUrl, function (string $chunk) use (&$m3u) {
            $m3u .= $chunk;
        });

        return $m3u;
    }

    /**
     * Write the playlist in chunks of about 64 KB to $write.
     *
     * The channel entries come from a ready list shared by viewers who see the same channels (ListCacheService), with a
     * placeholder where this viewer's token goes; the header and the viewer's favourites are written live.
     *
     * @param  ?string  $guideUrl  XMLTV address for the playlist header (x-tvg-url), so apps load the TV guide
     * @param  callable(string): mixed  $write
     */
    public function writeM3u(User $user, string $tokenValue, ?string $guideUrl, callable $write): void
    {
        // Build the play URL by replacing placeholders: route() per channel is slow at 100k entries
        $playTemplate = route('play', ['channel_id' => '__NEBOTV_ID__', 'token' => '__NEBOTV_TOKEN__']);
        $catchupTemplate = route('catchup', ['channel_id' => '__NEBOTV_ID__', 'token' => '__NEBOTV_TOKEN__']);
        $options = app(PlaylistOptionsService::class);

        $buffer = $guideUrl
            ? '#EXTM3U x-tvg-url="'.$this->escapeAttribute($guideUrl).'" url-tvg="'.$this->escapeAttribute($guideUrl)."\"\n"
            : "#EXTM3U\n";

        // The viewer's favourites first, in their own category, then everything in the usual order
        foreach (app(FavoriteService::class)->channels($user) as $channel) {
            $buffer .= $this->m3uEntry($channel, FavoriteService::CATEGORY, $playTemplate, $catchupTemplate, $tokenValue, $options);
        }

        $path = app(ListCacheService::class)->path($user, 'm3u', function (callable $cacheWrite) use ($user, $playTemplate, $catchupTemplate, $options) {
            $chunk = '';
            foreach (Channel::with('category')->listedFor($user)->playlistOrder()->lazy(1000) as $channel) {
                $chunk .= $this->m3uEntry($channel, $channel->category ? $channel->category->name : 'General', $playTemplate, $catchupTemplate, ListCacheService::TOKEN_PLACEHOLDER, $options);
                if (strlen($chunk) >= 65536) {
                    $cacheWrite($chunk);
                    $chunk = '';
                }
            }
            $cacheWrite($chunk);
        });

        // One entry per few lines and every line is short: replace the placeholder line by line, so it is never cut in two
        $handle = fopen($path, 'r');
        try {
            while (($line = fgets($handle)) !== false) {
                $buffer .= strtr($line, [ListCacheService::TOKEN_PLACEHOLDER => $tokenValue]);
                if (strlen($buffer) >= 65536) {
                    $write($buffer);
                    $buffer = '';
                }
            }
        } finally {
            fclose($handle);
        }

        $write($buffer);
    }

    /** One playlist entry. */
    private function m3uEntry(Channel $channel, string $categoryName, string $playTemplate, string $catchupTemplate, string $tokenValue, PlaylistOptionsService $options): string
    {
        // One pass (strtr): a value that happens to contain the other placeholder is not replaced again
        $playUrl = strtr($playTemplate, [
            '__NEBOTV_ID__' => (string) $channel->id,
            '__NEBOTV_TOKEN__' => $tokenValue.$this->streamExtension($channel->stream_url),
        ]);
        $name = $options->displayName($channel);

        // Catch-up when the provider offers it: apps fill in the start ({utc}) and length ({duration}) of the programme
        $catchup = '';
        if ($channel->catchup) {
            $catchupUrl = strtr($catchupTemplate, ['__NEBOTV_ID__' => (string) $channel->id, '__NEBOTV_TOKEN__' => $tokenValue]).'?utc={utc}&duration={duration}';
            $catchup = sprintf(' catchup="default" catchup-days="%d" catchup-source="%s"', $channel->catchup_days ?: CatchupService::DEFAULT_DAYS, $catchupUrl);
        }

        return sprintf(
            "#EXTINF:-1 tvg-id=\"%s\" tvg-name=\"%s\" tvg-logo=\"%s\"%s group-title=\"%s\"%s,%s\n%s%s\n",
            $this->escapeAttribute($channel->guideId() ?: (string) $channel->id),
            $this->escapeAttribute($name),
            $this->escapeAttribute($channel->logo()),
            $channel->number ? ' tvg-chno="'.$channel->number.'"' : '',
            $this->escapeAttribute($categoryName),
            $catchup,
            $this->stripLineBreaks($name),
            $this->vlcOptions($channel),
            $playUrl
        );
    }

    /**
     * Player identity / referring page the upstream needs. TiviMate, OTT Navigator, VLC and
     * Kodi send them on the play request and keep them through the redirect.
     */
    private function vlcOptions(Channel $channel): string
    {
        $options = '';

        if ($channel->http_user_agent) {
            $options .= '#EXTVLCOPT:http-user-agent='.$this->stripLineBreaks($channel->http_user_agent)."\n";
        }

        if ($channel->http_referrer) {
            $options .= '#EXTVLCOPT:http-referrer='.$this->stripLineBreaks($channel->http_referrer)."\n";
        }

        return $options;
    }

    /**
     * Mirror the upstream file extension (".m3u8", ".ts", ...) so players can pick the right demuxer.
     */
    private function streamExtension(string $streamUrl): string
    {
        $extension = strtolower(pathinfo((string) parse_url($streamUrl, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, ['m3u8', 'ts', 'mp4', 'mkv', 'avi', 'mov'], true) ? '.'.$extension : '';
    }

    /**
     * Make a value safe inside a quoted #EXTINF attribute.
     */
    private function escapeAttribute(?string $value): string
    {
        return str_replace('"', "'", $this->stripLineBreaks($value));
    }

    private function stripLineBreaks(?string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', (string) $value));
    }
}
