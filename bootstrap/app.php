<?php

use App\Http\Middleware\SetLocale;
use App\Support\Theme;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

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
        $middleware->web(append: [SetLocale::class]);
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);
        // Written by the theme switcher's JS, so it can't be encrypted
        $middleware->encryptCookies(except: [Theme::COOKIE]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
