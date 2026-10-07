<?php

namespace App\Services;

use App\Models\User;
use App\Models\AccessToken;
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

    public function getM3u(User $user, string $tokenValue)
    {
        $bouquets = $user->bouquets()->with('channels.category')->get();
        
        $m3u = "#EXTM3U\n";
        
        foreach ($bouquets as $bouquet) {
            foreach ($bouquet->channels as $channel) {
                if (!$channel->is_active) continue;
                
                $categoryName = $channel->category ? $channel->category->name : 'General';
                $playUrl = route('play', ['channel_id' => $channel->id, 'token' => $tokenValue]);
                
                $m3u .= sprintf(
                    "#EXTINF:-1 tvg-id=\"%s\" tvg-name=\"%s\" tvg-logo=\"%s\" group-title=\"%s\",%s\n%s\n",
                    $channel->id,
                    $this->escapeAttribute($channel->name),
                    $this->escapeAttribute($channel->logo_url),
                    $this->escapeAttribute($categoryName),
                    $this->stripLineBreaks($channel->name),
                    $playUrl
                );
            }
        }
        
        return $m3u;
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
