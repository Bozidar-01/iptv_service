<?php

namespace App\Services;

use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\StreamLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

class StreamService
{
    public function __construct(private ReceiverService $receiverService) {}

    /**
     * Minutes after the last play request that a session still counts as active.
     */
    public const SESSION_TIMEOUT_MINUTES = 5;

    /** Minutes a disconnected device can't start a stream again. */
    public const DEVICE_BLOCK_MINUTES = 15;

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
            'user_agent' => $this->userAgent(),
            'started_at' => now(),
        ]);

        // 2. Manage session
        $this->touch($user, $channel);

        // 3. Return target URL: the stream itself, or a signed link for receiver channels
        return $this->receiverService->playUrl($channel->playbackChannel());
    }

    /**
     * Check if user is allowed to watch the channel.
     */
    public function isAllowed(User $user, Channel $channel)
    {
        if (!$user->isActive() || !$channel->is_active || $this->isBlocked($user)) {
            return false;
        }

        // The channel must be in one of the user's bouquets and not hidden for them
        if (!Channel::availableTo($user)->whereKey($channel->id)->exists()) {
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

    /**
     * Has an admin disconnected the requesting device? It then can't play (or keep a proxied stream
     * running) until DEVICE_BLOCK_MINUTES have passed.
     */
    public function isBlocked(User $user): bool
    {
        return Cache::has($this->blockKey($user->id, Request::ip(), $this->userAgent()));
    }

    /**
     * Admin action: end a device's session and keep it out for DEVICE_BLOCK_MINUTES. A player that streams
     * straight from the provider keeps its current stream until it reconnects (the panel can't cut that);
     * through "Hide provider address" it stops within seconds.
     */
    public function disconnect(ActiveSession $session): void
    {
        Cache::put($this->blockKey($session->user_id, (string) $session->ip, (string) $session->user_agent), true, now()->addMinutes(self::DEVICE_BLOCK_MINUTES));
        $session->delete();
    }

    /**
     * @return int devices disconnected
     */
    public function disconnectAll(User $user): int
    {
        $sessions = $user->activeSessions()->get();
        $sessions->each(fn (ActiveSession $session) => $this->disconnect($session));

        return $sessions->count();
    }

    private function blockKey(int $userId, string $ip, string $userAgent): string
    {
        return 'device-blocked:'.$userId.':'.sha1($ip.'|'.mb_substr($userAgent, 0, 255));
    }

    /**
     * Record that the requesting device is watching $channel right now (keeps its slot taken).
     * Called on every play, and again for each part of a proxied stream.
     */
    public function touch(User $user, Channel $channel): void
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
