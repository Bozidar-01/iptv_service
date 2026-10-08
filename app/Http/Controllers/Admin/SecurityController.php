<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Services\TwoFactorService;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class SecurityController extends Controller
{
    protected $twoFactor;

    public function __construct(TwoFactorService $twoFactor)
    {
        $this->twoFactor = $twoFactor;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $secret = $this->twoFactor->pendingSecret($user);

        return view('admin.security.index', [
            'enabled' => $this->twoFactor->isEnabled($user),
            'secret' => $secret,
            'qr' => $secret ? $this->twoFactor->qrSvg($user, $secret) : null,
            'codesLeft' => $this->twoFactor->recoveryCodesLeft($user),
            'events' => LoginEvent::latest('id')->limit(30)->get(),
        ]);
    }

    /** Start setting up: shows the QR code. */
    public function start(Request $request)
    {
        if (!$this->twoFactor->isEnabled($request->user())) {
            $this->twoFactor->begin($request->user());
        }

        return redirect()->route('admin.security.index');
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $codes = $this->twoFactor->confirm($request->user(), $request->input('code'));
        if ($codes === null) {
            return back()->withErrors(['code' => __('That code is not right.')]);
        }

        LoginEvent::record(LoginEvent::TWO_FACTOR_ENABLED, $request->user()->username, $request->user(), $request);

        return redirect()->route('admin.security.index')
            ->with('success', __('Two-factor sign-in is on. Save your recovery codes now.'))
            ->with('recovery_codes', $codes);
    }

    public function recoveryCodes(Request $request)
    {
        if ($error = $this->checkPassword($request)) {
            return $error;
        }

        if (!$this->twoFactor->isEnabled($request->user())) {
            return redirect()->route('admin.security.index');
        }

        return redirect()->route('admin.security.index')
            ->with('success', __('New recovery codes created. The old ones no longer work.'))
            ->with('recovery_codes', $this->twoFactor->regenerateRecoveryCodes($request->user()));
    }

    public function disable(Request $request)
    {
        if ($error = $this->checkPassword($request)) {
            return $error;
        }

        $this->twoFactor->disable($request->user());
        LoginEvent::record(LoginEvent::TWO_FACTOR_DISABLED, $request->user()->username, $request->user(), $request);

        return redirect()->route('admin.security.index')->with('success', __('Two-factor sign-in is off.'));
    }

    /**
     * Changing security settings asks for the password again (limited like a sign-in).
     */
    private function checkPassword(Request $request)
    {
        $request->validate(['password' => ['required', 'string']]);

        $key = 'security-password:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, AuthController::MAX_ATTEMPTS)) {
            return back()->withErrors(['password' => __('Too many sign-in attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if (!Hash::check($request->input('password'), $request->user()->password)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['password' => __('The password is not right.')]);
        }

        RateLimiter::clear($key);

        return null;
    }
}
