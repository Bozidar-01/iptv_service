<?php

namespace App\Services;

use App\Models\ActiveSession;
use App\Models\Category;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Xtream Codes compatible API, so TV apps (IPTV Smarters, TiviMate, XCIPTV, ...) can log in
 * with server URL + username + password instead of an M3U link. Live TV only: the VOD and
 * series actions answer with empty lists, EPG with nothing.
 */
class XtreamService
{
    /** Wrong username/password attempts per IP before it is blocked for LOCKOUT_SECONDS. */
    public const MAX_FAILED_ATTEMPTS = 20;

    public const LOCKOUT_SECONDS = 600;

    /**
     * Check app credentials (Xtream API, /m3u, /get.php, /live). After too many wrong attempts
     * from one IP every attempt fails for a while, even with the right password, which stops
     * password guessing. Apps retry a few calls with a mistyped password, so the limit is generous.
     */
    public function authenticate(mixed $username, mixed $password): ?User
    {
        if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
            return null;
        }

        $throttleKey = 'player-login:'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILED_ATTEMPTS)) {
            return null;
        }

        $user = User::where('username', $username)->first();

        if ($user && Hash::check($password, $user->password)) {
            return $user;
        }

        RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

        return null;
    }

    public function status(User $user): string
    {
        return match (true) {
            $user->status !== 'active' => 'Disabled',
            $user->isExpired() => 'Expired',
            default => 'Active',
        };
    }

    /**
     * Response to player_api.php without an action: the login check apps do first.
     */
    public function loginInfo(User $user, string $password, Request $request): array
    {
        $activeConnections = ActiveSession::where('user_id', $user->id)
            ->where('last_activity_at', '>', now()->subMinutes(StreamService::SESSION_TIMEOUT_MINUTES))
            ->count();

        return [
            'user_info' => [
                'username' => $user->username,
                'password' => $password,
                'message' => '',
                'auth' => 1,
                'status' => $this->status($user),
                'exp_date' => $user->expiration_date ? (string) $user->expiration_date->timestamp : null,
                'is_trial' => $user->is_trial ? '1' : '0',
                'active_cons' => (string) $activeConnections,
                'created_at' => (string) $user->created_at?->timestamp,
                'max_connections' => (string) $user->max_connections,
                'allowed_output_formats' => ['m3u8', 'ts'],
            ],
            'server_info' => $this->serverInfo($request),
        ];
    }

    public function serverInfo(Request $request): array
    {
        $https = $request->isSecure();
        $port = (string) $request->getPort();

        return [
            'url' => $request->getHost(),
            // The port this request came in on: apps that build their later requests from server_info (protocol + url + port)
            // must not end up on https://host:80
            'port' => $port,
            'https_port' => $https ? $port : '443',
            'server_protocol' => $https ? 'https' : 'http',
            'rtmp_port' => '0',
            'timezone' => config('app.timezone'),
            'timestamp_now' => now()->timestamp,
            'time_now' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Categories that hold at least one channel the user can watch. Channels without a
     * category are listed under id "0" (same "General" group as the M3U playlist).
     */
    public function liveCategories(User $user): array
    {
        $ids = Channel::availableTo($user)->distinct()->pluck('category_id');
        $favourites = app(FavoriteService::class)->count($user) > 0;

        $categories = Category::whereIn('id', $ids->filter())->orderBy('name')->get()
            ->map(fn (Category $category) => [
                'category_id' => (string) $category->id,
                'category_name' => $category->name,
                'parent_id' => 0,
            ]);

        if ($ids->contains(null)) {
            $categories->push(['category_id' => '0', 'category_name' => 'General', 'parent_id' => 0]);
        }

        // The viewer's favourites are the first category
        if ($favourites) {
            $categories->prepend(['category_id' => FavoriteService::XTREAM_CATEGORY, 'category_name' => FavoriteService::CATEGORY, 'parent_id' => 0]);
        }

        return $categories->values()->all();
    }

    /**
     * The channels of the "Favourites" category in the viewer's order (they are also listed in their own categories).
     *
     * @return Collection<int, Channel>
     */
    public function favouriteStreams(User $user): Collection
    {
        return app(FavoriteService::class)->channels($user);
    }

    public function liveStreamsQuery(User $user, mixed $categoryId = null): Builder
    {
        return Channel::availableTo($user)
            ->when(is_numeric($categoryId), fn ($q) => (int) $categoryId === 0
                ? $q->whereNull('category_id')
                : $q->where('category_id', (int) $categoryId))
            ->playlistOrder();
    }

    public function liveStream(Channel $channel, int $num, ?string $category = null): array
    {
        $categoryId = $category ?? $channel->category_id ?? 0;

        return [
            'num' => $num,
            'name' => $channel->name,
            'stream_type' => 'live',
            'stream_id' => $channel->id,
            'stream_icon' => $channel->logo_url ?? '',
            'epg_channel_id' => $channel->guideId(),
            'added' => (string) $channel->created_at?->timestamp,
            'is_adult' => 0,
            'category_id' => (string) $categoryId,
            'category_ids' => [$categoryId],
            'custom_sid' => '',
            'tv_archive' => 0,
            'direct_source' => '',
            'tv_archive_duration' => 0,
        ];
    }
}
