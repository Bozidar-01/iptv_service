<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function getAllUsers()
    {
        return User::with('bouquets')->paginate(20);
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
