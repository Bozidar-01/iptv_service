<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Remember the chosen panel language in a long-lived cookie (survives sign-out).
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(config('app.locales')))],
        ]);

        return back()->withCookie(cookie()->forever(SetLocale::COOKIE, $data['locale']));
    }
}
