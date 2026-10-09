<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserService
{
    /**
     * Filters: search (username), status ("active" | "disabled" | "expired").
     * $actor limits the list to what they may see (a reseller: their own customers).
     */
    public function getAllUsers(array $filters = [], ?User $actor = null)
    {
        return User::visibleTo($actor)->with(['bouquets', 'owner'])
            ->when($filters['reseller'] ?? null, fn ($q, $reseller) => $q->where('owner_id', $reseller))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('username', 'like', '%'.$search.'%'))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('expiration_date')->orWhere('expiration_date', '>', now())))
            ->when(($filters['status'] ?? null) === 'disabled', fn ($q) => $q->where('status', 'disabled'))
            ->when(($filters['status'] ?? null) === 'expired', fn ($q) => $q->where('expiration_date', '<=', now()))
            ->orderBy('role')
            ->orderBy('username')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Number of distinct active channels the user gets: those in their bouquets that aren't hidden for them.
     */
    public function channelCount(User $user): int
    {
        return Channel::availableTo($user)->count();
    }

    public function createUser(array $data)
    {
        $data['password_plain'] = $data['password'];
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        if (isset($data['bouquets'])) {
            $user->bouquets()->sync($data['bouquets']);
        }

        return $user;
    }

    public function updateUser(User $user, array $data)
    {
        if (isset($data['password']) && $data['password']) {
            $data['password_plain'] = $data['password'];
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        // Moving the end date later turns a trial into a real subscription
        if ($user->is_trial && !empty($data['expiration_date']) && $user->expiration_date && Carbon::parse($data['expiration_date'])->gt($user->expiration_date)) {
            $data['is_trial'] = false;
        }

        $user->update($data);
        if (isset($data['bouquets'])) {
            $user->bouquets()->sync($data['bouquets']);
        }

        return $user;
    }

    /**
     * A short test account: random username and password, one device, ends in $hours.
     * $owner is the reseller it belongs to, or null (the admins' own users).
     *
     * @param  list<int>  $bouquetIds
     */
    public function createTrial(?User $owner, int $hours, array $bouquetIds): User
    {
        do {
            $username = 'trial'.random_int(10000, 99999);
        } while (User::where('username', $username)->exists());

        return $this->createUser([
            'username' => $username,
            'password' => Str::lower(Str::random(8)),
            'status' => 'active',
            'role' => 'user',
            'owner_id' => $owner?->id,
            'max_connections' => 1,
            'expiration_date' => now()->addHours($hours),
            'is_trial' => true,
            'bouquets' => $bouquetIds,
        ]);
    }

    /**
     * Trials a reseller made in the last 24 hours (limited by iptv.trials_per_day).
     */
    public function recentTrials(User $owner): int
    {
        return User::where('owner_id', $owner->id)->where('is_trial', true)->where('created_at', '>', now()->subDay())->count();
    }

    /**
     * Text to send to a subscriber so they can log in: server, username, password, playlist link, their page and
     * the end date. Written in the subscriber's own language when they have one.
     */
    public function loginMessage(User $user, string $serverUrl, string $playlistUrl, string $pageUrl): string
    {
        $previous = App::getLocale();
        $locale = array_key_exists((string) $user->locale, config('app.locales')) ? $user->locale : $previous;
        App::setLocale($locale);

        try {
            $lines = [
                config('app.name'),
                __('Server URL').': '.$serverUrl,
                __('Username').': '.$user->username,
            ];
            if ($user->password_plain) {
                $lines[] = __('Password').': '.$user->password_plain;
            }
            $lines[] = __('Playlist link').': '.$playlistUrl;
            $lines[] = __('Your page (expiry and renewal)').': '.$pageUrl;
            if ($user->expiration_date) {
                $lines[] = __('Valid until').': '.$user->expiration_date->translatedFormat('j F Y');
            }

            return implode("\n", $lines);
        } finally {
            App::setLocale($previous);
        }
    }

    public function deleteUser(User $user)
    {
        return $user->delete();
    }

    public function toggleStatus(User $user)
    {
        $user->status = $user->status === 'active' ? 'disabled' : 'active';

        return $user->save();
    }
}
