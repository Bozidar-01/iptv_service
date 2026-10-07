<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use App\Models\StreamLog;
use App\Models\ActiveSession;
use Illuminate\Support\Facades\Request;

class StreamService
{
    public function __construct(private ReceiverService $receiverService)
    {
    }

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

        // 3. Return target URL: the stream itself, or a signed link for receiver channels
        return $this->receiverService->playUrl($channel->playbackChannel());
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

        // Check max connections: one slot per device (IP + user agent). The device's own session
        // doesn't count, so it can switch channels; any other device needs a free slot.
        $otherDevices = ActiveSession::where('user_id', $user->id)
            ->where('last_activity_at', '>', now()->subMinutes(self::SESSION_TIMEOUT_MINUTES))
            ->whereNot(fn ($query) => $this->whereDevice($query))
            ->count();

        if ($otherDevices >= $user->max_connections) {
            return false;
        }

        return true;
    }

    private function updateSession(User $user, Channel $channel)
    {
        // One row per device: switching channels updates it
        $session = $this->whereDevice(ActiveSession::where('user_id', $user->id))->first()
            ?? new ActiveSession(['user_id' => $user->id, 'ip' => Request::ip(), 'user_agent' => $this->userAgent()]);

        // A new channel or a session that went stale counts as a new viewing, so restart it
        if (!$session->exists || $session->channel_id != $channel->id
            || $session->last_activity_at <= now()->subMinutes(self::SESSION_TIMEOUT_MINUTES)) {
            $session->started_at = now();
        }

        $session->channel_id = $channel->id;
        $session->last_activity_at = now();
        $session->save();
    }

    /**
     * Limit a session query to the requesting device.
     */
    private function whereDevice($query)
    {
        return $query->where('ip', Request::ip())->where('user_agent', $this->userAgent());
    }

    private function userAgent(): string
    {
        return mb_substr((string) Request::userAgent(), 0, 255);
    }
}
