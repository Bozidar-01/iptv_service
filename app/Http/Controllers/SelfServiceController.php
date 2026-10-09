<?php

namespace App\Http\Controllers;

use App\Models\StreamAlert;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The page a subscriber opens with their personal link (/me/{playlist token}, the same secret as the
 * playlist link): their status and expiry, the playlist link, how to set it up, and a button that asks
 * the admin to renew. No sign-in, no session, no cookies (it lives with the TV-app routes). Never shows
 * the password: it is stored hashed.
 */
class SelfServiceController extends Controller
{
    /** Ask-to-renew requests per user: one a day, so the button can't flood the alerts. */
    public const RENEWAL_COOLDOWN_HOURS = 24;

    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function show(Request $request, string $token)
    {
        $user = $this->subscriber($token);
        $this->applyLocale($request);

        $user->load('bouquets')->loadCount(['activeSessions' => fn ($query) => $query->active()]);

        return view('me.show', [
            'user' => $user,
            'token' => $token,
            'playlistUrl' => route('playlist', ['token' => $token]),
            'serverUrl' => $request->getSchemeAndHttpHost(),
            'channelCount' => $this->userService->channelCount($user),
            'canRenew' => $user->expiration_date !== null && $user->expiration_date->lt(now()->addDays(30)),
            'sent' => $request->query('sent'),
            'lang' => App::getLocale(),
        ]);
    }

    public function renew(Request $request, string $token)
    {
        $user = $this->subscriber($token);

        $recent = StreamAlert::where('type', StreamAlert::TYPE_RENEWAL_REQUESTED)
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subHours(self::RENEWAL_COOLDOWN_HOURS))
            ->exists();

        if (!$recent) {
            StreamAlert::create(['type' => StreamAlert::TYPE_RENEWAL_REQUESTED, 'user_id' => $user->id, 'name' => $user->username]);
        }

        return redirect()->route('me', array_filter(['token' => $token, 'sent' => $recent ? 'again' : 'yes', 'lang' => $request->query('lang')]));
    }

    private function subscriber(string $token): User
    {
        // Admins use the panel; an unknown or old link is just "not found"
        return User::where('playlist_token', $token)->where('role', 'user')->firstOrFail();
    }

    /**
     * ?lang=en|mk, else the browser's language, else the default. Nothing is stored (no cookies here).
     */
    private function applyLocale(Request $request): void
    {
        $codes = array_keys(config('app.locales'));
        $lang = $request->query('lang');

        App::setLocale(in_array($lang, $codes, true) ? $lang : ($request->getPreferredLanguage($codes) ?? config('app.locale')));
    }
}
