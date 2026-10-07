<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the first admin if it doesn't exist (safe to re-run; never changes an existing user).
 *
 * Username and password come from ADMIN_USERNAME / ADMIN_PASSWORD in .env. Without a password,
 * local installs get "admin123" and any other environment a random one, printed once.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('ADMIN_USERNAME') ?: 'admin';

        if (User::where('username', $username)->exists()) {
            return;
        }

        $password = env('ADMIN_PASSWORD') ?: (app()->environment('local', 'testing') ? 'admin123' : Str::password(16, symbols: false));

        User::create([
            'username' => $username,
            'password' => Hash::make($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        if (!env('ADMIN_PASSWORD') && !app()->environment('local', 'testing')) {
            $this->command?->warn("Created admin \"{$username}\" with password: {$password}  (shown once; change it after signing in)");
        }
    }
}
