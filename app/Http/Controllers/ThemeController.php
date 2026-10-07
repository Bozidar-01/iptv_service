<?php

namespace App\Http\Controllers;

use App\Support\Theme;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    /**
     * Remember light/dark mode in a long-lived cookie. "system" removes it to follow the OS again.
     * Used when JavaScript is off; the switcher's script sets the same cookie without a request.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(Theme::OPTIONS)],
        ]);

        $cookie = $data['theme'] === 'system'
            ? cookie()->forget(Theme::COOKIE)
            : cookie()->forever(Theme::COOKIE, $data['theme']);

        return back()->withCookie($cookie);
    }
}
