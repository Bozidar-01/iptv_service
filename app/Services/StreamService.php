<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use App\Models\StreamLog;
use App\Models\ActiveSession;
use Illuminate\Support\Facades\Request;

class StreamService
{
    /**
     * Minutes after the last play request that a session still counts as active.
     */
    public const SESSION_TIMEOUT_MINUTES = 5;

    /**
     * Get stream URL for a user and channel.
     * Initially just returns the channel stream URL, but prepared for proxy logic.
     */
    public function getStreamUrl(User $user, Channel $channel)
    {
        // 1. Log the attempt
        StreamLog::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'started_at' => now(),
        ]);

        // 2. Manage session
        $this->updateSession($user, $channel);

        // 3. Return target URL (Direct redirect for now)
        return $channel->stream_url;
    }

    /**
     * Check if user is allowed to watch the channel.
     */
    public function isAllowed(User $user, Channel $channel)
    {
        if (!$user->isActive() || !$channel->is_active) {
            return false;
        }

        // Check if channel is in user's bouquets
        $hasAccess = $user->bouquets()->whereHas('channels', function ($query) use ($channel) {
            $query->where('channels.id', $channel->id);
        })->exists();

        if (!$hasAccess) {
            return false;
        }

        // Check max connections
        $activeSessions = ActiveSession::where('user_id', $user->id)
            ->where('last_activity_at', '>', now()->subMinutes(self::SESSION_TIMEOUT_MINUTES));

        if ((clone $activeSessions)->count() >= $user->max_connections) {
            // Re-requesting a channel the user is already actively watching doesn't use a new slot
            $isSameStream = (clone $activeSessions)
                ->where('channel_id', $channel->id)
                ->exists();

            if (!$isSameStream) {
                return false;
            }
        }

        return true;
    }

    private function updateSession(User $user, Channel $channel)
    {
        $session = ActiveSession::firstOrNew(['user_id' => $user->id, 'channel_id' => $channel->id]);

        // A session that went stale counts as a new viewing, so restart it
        if (!$session->exists || $session->last_activity_at <= now()->subMinutes(self::SESSION_TIMEOUT_MINUTES)) {
            $session->started_at = now();
        }

        $session->last_activity_at = now();
        $session->save();
    }
}
