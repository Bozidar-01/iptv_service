<?php

namespace App\Services;

use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\DeniedPlay;
use App\Models\StreamLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

class StreamService
{
    public function __construct(private ReceiverService $receiverService, private ChannelHealthService $health, private ChannelFailoverService $failover) {}

    /** Minutes a device keeps the copy it was moved to after retrying a channel. */
    public const RETRY_CHOICE_MINUTES = 10;

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

        // 3. A device that keeps asking for the channel can't play it: try the next working copy (ChannelFailoverService)
        $attempt = $this->health->recordPlay($user, $channel, $this->deviceId());
        if ($attempt >= ChannelHealthService::RETRY_PLAYS) {
            Cache::put($this->choiceKey($user, $channel), $this->failover->forRetry($channel, $attempt)->id, now()->addMinutes(self::RETRY_CHOICE_MINUTES));
        }
        $this->applyDeviceChoice($user, $channel);

        // 4. Return target URL: the stream itself, or a signed link for receiver channels
        return $this->receiverService->playUrl($channel->playbackChannel());
    }

    /**
     * Play the copy this device was moved to after retrying (for a while), also for the parts of a stream played through the
     * server. Only in memory: other viewers keep the channel's own stream.
     */
    public function applyDeviceChoice(User $user, Channel $channel): void
    {
        $chosen = Cache::get($this->choiceKey($user, $channel));
        if ($chosen === null) {
            return;
        }

        if ((int) $chosen === $channel->id) {
            $channel->using_backup_id = null;
            $channel->setRelation('usingBackup', null);

            return;
        }

        $copy = Channel::find($chosen);
        if ($copy && $copy->is_active) {
            $channel->using_backup_id = $copy->id;
            $channel->setRelation('usingBackup', $copy);
        }
    }

    private function choiceKey(User $user, Channel $channel): string
    {
        return 'retry-choice:'.$user->id.':'.$channel->id.':'.sha1($this->deviceId());
    }

    /**
     * Check if user is allowed to watch the channel.
     */
    public function isAllowed(User $user, Channel $channel)
    {
        return $this->denialReason($user, $channel) === null;
    }

    /**
     * Why this user can't play this channel now (a DeniedPlay constant), or null when they can.
     */
    public function denialReason(User $user, Channel $channel): ?string
    {
        if ($user->status !== 'active') {
            return DeniedPlay::DISABLED;
        }
        if ($user->isExpired()) {
            return DeniedPlay::EXPIRED;
        }
        if ($this->isBlocked($user)) {
            return DeniedPlay::BLOCKED;
        }
        if (!$channel->is_active) {
            return DeniedPlay::CHANNEL_OFF;
        }

        // The channel must be in one of the user's bouquets and not hidden for them
        if (!Channel::availableTo($user)->whereKey($channel->id)->exists()) {
            $inBouquet = $user->bouquets()->whereHas('channels', fn ($q) => $q->where('channels.id', $channel->id))->exists();

            return $inBouquet ? DeniedPlay::HIDDEN : DeniedPlay::NOT_IN_BOUQUET;
        }

        // Check max connections: one slot per device. The device's own session doesn't count, so it can
        // switch channels; any other device needs a free slot.
        $otherDevices = ActiveSession::where('user_id', $user->id)
            ->where('last_activity_at', '>', now()->subMinutes(self::SESSION_TIMEOUT_MINUTES))
            ->whereNot(fn ($query) => $this->whereDevice($query))
            ->whereNot(fn ($query) => $this->whereReplaced($query))
            ->count();

        if ($otherDevices >= $user->max_connections) {
            return DeniedPlay::LIMIT;
        }

        return null;
    }

    /**
     * Check a play and, when it is refused, record why (once a minute per user, channel and reason, so a player that retries
     * does not fill the list). Returns whether the user may play.
     */
    public function authorize(User $user, Channel $channel): bool
    {
        $reason = $this->denialReason($user, $channel);

        if ($reason !== null && Cache::add("denied:{$user->id}:{$channel->id}:{$reason}", true, now()->addMinute())) {
            DeniedPlay::create(['user_id' => $user->id, 'channel_id' => $channel->id, 'reason' => $reason, 'ip' => Request::ip(), 'user_agent' => $this->userAgent()]);
        }

        return $reason === null;
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
        // One row per device: switching channels updates it. A device that loaded its playlist again takes over its old row.
        $session = $this->whereDevice(ActiveSession::where('user_id', $user->id))->first()
            ?? $this->whereReplaced(ActiveSession::where('user_id', $user->id))->latest('last_activity_at')->first()
            ?? new ActiveSession(['user_id' => $user->id, 'ip' => Request::ip(), 'user_agent' => $this->userAgent()]);
        $session->device_key = $this->deviceKey();

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
     * Limit a session query to the requesting device: by the device key in its play links (M3U playlists, see TokenService),
     * else by IP + user agent (Xtream apps build their own links).
     */
    private function whereDevice($query)
    {
        $key = $this->deviceKey();

        return $key !== null
            ? $query->where('device_key', $key)
            : $query->whereNull('device_key')->where('ip', Request::ip())->where('user_agent', $this->userAgent());
    }

    /**
     * Sessions the requesting device takes over: when it loads its playlist again it gets a new key, and its sessions from the
     * same IP and app that were last used before that key was made are its own old ones. A second identical TV behind the same
     * router keeps playing with its own key after that time, so it keeps its own slot.
     */
    private function whereReplaced($query)
    {
        $key = $this->deviceKey();
        if ($key === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('ip', Request::ip())->where('user_agent', $this->userAgent())
            ->where(fn ($q) => $q->whereNull('device_key')
                ->orWhere(fn ($q) => $q->where('device_key', '!=', $key)->where('last_activity_at', '<', TokenService::deviceKeyIssuedAt($key))));
    }

    /** The device key of the play link being answered (set by PlayController), or null. */
    public function deviceKey(): ?string
    {
        $key = Request::instance()->attributes->get('device_key');

        return is_string($key) ? $key : null;
    }

    /** What tells this device apart: its key, else IP + user agent. */
    private function deviceId(): string
    {
        return $this->deviceKey() ?? Request::ip().'|'.$this->userAgent();
    }

    private function userAgent(): string
    {
        return mb_substr((string) Request::userAgent(), 0, 255);
    }
}
