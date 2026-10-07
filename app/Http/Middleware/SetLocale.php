<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the language chosen in the switcher (the "locale" cookie).
 * Falls back to APP_LOCALE when the cookie is missing or not a supported language.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);

        if (is_string($locale) && array_key_exists($locale, config('app.locales'))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
