<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** Failed sign-ins allowed per username + IP before a one-minute wait. */
    public const MAX_ATTEMPTS = 5;

    /** A bcrypt hash of a random string, checked when the username doesn't exist. */
    private const DUMMY_HASH = '$2y$12$6kipqWtnvwyP5XxG.8/Fk.rV4G9f1PME8RDqOJOTJE/Vq3VJKKAsS';

    /** Failed sign-ins allowed per IP (any username) per minute: stops trying many usernames. */
    public const MAX_ATTEMPTS_PER_IP = 20;

    /** Minutes to enter the two-factor code after the password was right. */
    public const TWO_FACTOR_MINUTES = 5;

    protected $twoFactor;

    public function __construct(TwoFactorService $twoFactor)
    {
        $this->twoFactor = $twoFactor;
    }

    public function showLogin()
    {
        if (Auth::check() && Auth::user()->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required'],
        ]);

        $throttleKey = 'login:'.Str::lower($credentials['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return back()->withErrors([
                'username' => __('Too many sign-in attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ])->onlyInput('username');
        }

        $ipKey = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP)) {
            return back()->withErrors([
                'username' => __('Too many sign-in attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($ipKey)]),
            ])->onlyInput('username');
        }

        $provider = Auth::guard()->getProvider();
        /** @var User|null $user */
        $user = $provider->retrieveByCredentials(['username' => $credentials['username']]);

        if ($user && $provider->validateCredentials($user, ['password' => $credentials['password']])) {
            if ($user->role !== 'admin') {
                LoginEvent::record(LoginEvent::DENIED, $credentials['username'], $user, $request);
                RateLimiter::hit($throttleKey, 60);
                RateLimiter::hit($ipKey, 60);

                return back()->withErrors([
                    'username' => __('Unauthorized access.'),
                ]);
            }

            RateLimiter::clear($throttleKey);

            // Password is right but the account needs a code from the authenticator app
            if ($this->twoFactor->isEnabled($user)) {
                $request->session()->put('two_factor', [
                    'id' => $user->id,
                    'remember' => $request->boolean('remember'),
                    'expires' => now()->addMinutes(self::TWO_FACTOR_MINUTES)->timestamp,
                ]);

                return redirect()->route('two-factor.show');
            }

            return $this->signIn($request, $user, $request->boolean('remember'));
        }

        // Unknown username: still spend the time of a password check, so response time doesn't tell
        if (!$user) {
            Hash::check($credentials['password'], self::DUMMY_HASH);
        }

        LoginEvent::record(LoginEvent::FAILED, $credentials['username'], $user, $request);
        RateLimiter::hit($throttleKey, 60);
        RateLimiter::hit($ipKey, 60);

        return back()->withErrors([
            'username' => __('The provided credentials do not match our records.'),
        ])->onlyInput('username');
    }

    public function showTwoFactor(Request $request)
    {
        return $this->pendingUser($request) ? view('auth.two-factor') : redirect()->route('login');
    }

    public function twoFactor(Request $request)
    {
        $user = $this->pendingUser($request);
        if (!$user) {
            return redirect()->route('login')->withErrors(['username' => __('The sign-in took too long. Please start again.')]);
        }

        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $throttleKey = 'two-factor:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return back()->withErrors([
                'code' => __('Too many sign-in attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        if (!$this->twoFactor->verify($user, $request->input('code'))) {
            LoginEvent::record(LoginEvent::TWO_FACTOR_FAILED, $user->username, $user, $request);
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['code' => __('That code is not right.')]);
        }

        RateLimiter::clear($throttleKey);
        $remember = $request->session()->pull('two_factor')['remember'];

        return $this->signIn($request, $user, $remember);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function signIn(Request $request, User $user, bool $remember)
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        LoginEvent::record(LoginEvent::SUCCESS, $user->username, $user, $request);

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * The admin whose password was just accepted and who still has to give a code.
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor');
        if (!$pending || $pending['expires'] < now()->timestamp) {
            $request->session()->forget('two_factor');

            return null;
        }

        $user = User::find($pending['id']);

        return $user && $user->isAdmin() && $this->twoFactor->isEnabled($user) ? $user : null;
    }
}
