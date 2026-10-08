<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\Theme;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // TV apps call these constantly: no session, cookies or CSRF
        then: function () {
            Route::group([], base_path('routes/player.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer / Cloudflare / TLS proxy: TRUSTED_PROXIES=* or a comma-separated list, so the real client IP
        // (rate limits, sign-in history, device slots) and https are seen. Empty = trust none.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->web(append: [SetLocale::class, SecurityHeaders::class]);
        // Check who is asking before {user} is resolved (the binding is scoped to what the person may see)
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAdmin::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureStaff::class);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'staff' => EnsureStaff::class,
        ]);
        // Written by the theme switcher's JS, so it can't be encrypted
        $middleware->encryptCookies(except: [Theme::COOKIE]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report errors to Sentry when SENTRY_LARAVEL_DSN is set (see config/sentry.php)
        Integration::handles($exceptions);
    })->create();
