<?php

namespace App\Providers;

use App\Models\StreamAlert;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // {user} in the panel: a reseller reaches only their own customers (anything else is a 404, same as a
        // missing id), an admin everyone. See User::scopeVisibleTo.
        Route::bind('user', fn ($value) => User::visibleTo(Auth::user())->findOrFail($value));

        // Request limit for /play, /playlist/{token}.m3u and /epg/{token}.xml
        RateLimiter::for('player', fn (Request $request) => [
            Limit::perMinute(config('iptv.throttle_per_token'))
                ->by('token:'.$request->route('token').'|'.$request->ip()),
            Limit::perMinute(config('iptv.throttle_per_ip'))->by('ip:'.$request->ip()),
        ]);

        // A subscriber's own page: a few requests a minute are plenty
        RateLimiter::for('me', fn (Request $request) => [
            Limit::perMinute(30)->by('me:'.$request->route('token').'|'.$request->ip()),
            Limit::perMinute(config('iptv.throttle_per_ip'))->by('ip:'.$request->ip()),
        ]);

        // Xtream player_api.php: apps ask for categories, channels and guides in bursts, so a high limit per IP
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('iptv.throttle_api_per_ip'))->by('api:'.$request->ip()));

        // Xtream /live/{username}/{password}/{stream}: starts a stream like /play, but is keyed by username
        RateLimiter::for('live', fn (Request $request) => [
            Limit::perMinute(config('iptv.throttle_per_token'))->by('live:'.$request->route('username').'|'.$request->ip()),
            Limit::perMinute(config('iptv.throttle_per_ip'))->by('ip:'.$request->ip()),
        ]);

        // Parts of a proxied stream: one viewer asks for a segment every few seconds
        RateLimiter::for('stream', fn (Request $request) => [
            Limit::perMinute(config('iptv.throttle_stream_per_token'))
                ->by('stream:'.$request->route('token').'|'.$request->ip()),
            Limit::perMinute(config('iptv.throttle_per_ip') * 5)->by('stream-ip:'.$request->ip()),
        ]);

        // Header bell (admin.partials.notifications)
        View::composer('layouts.admin', function ($view) {
            $user = Auth::user();

            $view->with('alertMenu', match (true) {
                $user?->isAdmin() => ['unread' => StreamAlert::unread()->count(), 'latest' => StreamAlert::latest('id')->limit(8)->get()],
                // A reseller sees the alerts of their own customers; read/unread belongs to the admins, so no unread count
                $user?->isReseller() => ['unread' => 0, 'latest' => StreamAlert::whereIn('user_id', User::visibleTo($user)->select('id'))->latest('id')->limit(8)->get()],
                default => ['unread' => 0, 'latest' => collect()],
            });
        });
    }
}
