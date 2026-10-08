<?php

namespace App\Providers;

use App\Models\StreamAlert;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
        // Request limit for /play, /playlist/{token}.m3u and /epg/{token}.xml
        RateLimiter::for('player', fn (Request $request) => [
            Limit::perMinute(config('iptv.throttle_per_token'))
                ->by('token:'.$request->route('token').'|'.$request->ip()),
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
            $view->with('alertMenu', Auth::check() ? [
                'unread' => StreamAlert::unread()->count(),
                'latest' => StreamAlert::latest('id')->limit(8)->get(),
            ] : ['unread' => 0, 'latest' => collect()]);
        });
    }
}
