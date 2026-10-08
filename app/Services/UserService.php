<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserService
{
    /**
     * Filters: search (username), status ("active" | "disabled" | "expired").
     */
    public function getAllUsers(array $filters = [])
    {
        return User::with('bouquets')
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
     * Number of distinct active channels the user's bouquets give access to.
     */
    public function channelCount(User $user): int
    {
        return Channel::where('is_active', true)
            ->whereHas('bouquets', fn ($q) => $q->whereIn('bouquets.id', $user->bouquets()->select('bouquets.id')))
            ->count();
    }

    public function createUser(array $data)
    {
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
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);
        if (isset($data['bouquets'])) {
            $user->bouquets()->sync($data['bouquets']);
        }

        return $user;
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
