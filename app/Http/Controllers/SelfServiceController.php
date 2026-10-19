<?php

namespace App\Http\Controllers;

use App\Models\StreamAlert;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\FavoriteService;
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

    protected $favorites;

    public function __construct(UserService $userService, FavoriteService $favorites)
    {
        $this->userService = $userService;
        $this->favorites = $favorites;
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
            'favourites' => $this->favorites->channels($user),
            'favouritesMax' => FavoriteService::MAX,
            'search' => is_string($request->query('q')) ? trim($request->query('q')) : '',
            'found' => is_string($request->query('q')) ? $this->favorites->search($user, $request->query('q')) : collect(),
            'lang' => App::getLocale(),
            'announcement' => app(AnnouncementService::class)->current(),
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

    /** Add (`add`), remove (`remove`), or move up / down (`up`, `down`) a favourite channel. */
    public function favourite(Request $request, string $token)
    {
        $user = $this->subscriber($token);
        // This route has no session, so a bad request is simply refused
        $do = (string) $request->input('do');
        abort_unless(in_array($do, ['add', 'remove', 'up', 'down'], true) && ctype_digit((string) $request->input('channel')), 422);
        $channel = (int) $request->input('channel');

        match ($do) {
            'add' => $this->favorites->add($user, $channel),
            'remove' => $this->favorites->remove($user, $channel),
            'up' => $this->favorites->move($user, $channel, -1),
            'down' => $this->favorites->move($user, $channel, 1),
        };

        return redirect()->to(route('me', array_filter(['token' => $token, 'lang' => $request->query('lang'), 'q' => $request->query('q')])).'#favourites');
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
