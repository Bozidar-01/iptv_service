<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\Category;
use App\Models\Channel;
use App\Models\User;
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

    public function getM3u(User $user, string $tokenValue)
    {
        $channels = Channel::with('category')
            ->where('is_active', true)
            ->whereHas('bouquets', fn ($query) => $query->whereIn('bouquets.id', $user->bouquets()->select('bouquets.id')))
            ->orderBy(Category::select('name')->whereColumn('categories.id', 'channels.category_id'))
            ->orderBy('sort_order')
            ->orderBy('name');

        $m3u = "#EXTM3U\n";

        foreach ($channels->lazy(1000) as $channel) {
            $categoryName = $channel->category ? $channel->category->name : 'General';
            $playUrl = route('play', ['channel_id' => $channel->id, 'token' => $tokenValue.$this->streamExtension($channel->stream_url)]);

            $m3u .= sprintf(
                "#EXTINF:-1 tvg-id=\"%s\" tvg-name=\"%s\" tvg-logo=\"%s\" group-title=\"%s\",%s\n%s\n",
                $this->escapeAttribute($channel->tvg_id ?: (string) $channel->id),
                $this->escapeAttribute($channel->name),
                $this->escapeAttribute($channel->logo_url),
                $this->escapeAttribute($categoryName),
                $this->stripLineBreaks($channel->name),
                $playUrl
            );
        }

        return $m3u;
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
