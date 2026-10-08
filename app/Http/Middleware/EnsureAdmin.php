<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only area (sources, channels, bouquets, TV guide, statistics): only users with role "admin".
 * A reseller who walks in gets a plain 403 (they belong to the panel, just not here); any other session
 * (a subscriber, which login already refuses) is ended rather than shown an error page.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isReseller() && $request->user()->canUsePanel()) {
            abort(403);
        }

        if (!$request->user()?->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
