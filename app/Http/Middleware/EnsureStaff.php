<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The part of the panel admins and resellers share (users, activity, alerts, security). Anyone else, or a
 * reseller that has been switched off or has expired, is signed out at once.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->canUsePanel()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
