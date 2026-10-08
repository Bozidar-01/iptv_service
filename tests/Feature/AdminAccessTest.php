<?php

use App\Models\User;
use Database\Seeders\AdminSeeder;

it('ends a subscriber session that reaches the admin area', function () {
    $this->actingAs(User::factory()->create(['role' => 'user']));

    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('creates the first admin from ADMIN_USERNAME and ADMIN_PASSWORD', function () {
    // Read through config('iptv.admin_*'), so it also works when the config is cached
    config(['iptv.admin_username' => 'owner', 'iptv.admin_password' => 'from-env-pass']);

    $this->seed(AdminSeeder::class);
    $this->seed(AdminSeeder::class);

    $admin = User::where('username', 'owner')->sole();
    expect($admin->role)->toBe('admin')
        ->and(Hash::check('from-env-pass', $admin->password))->toBeTrue();
});

it('uses a random password outside local and never changes an existing admin', function () {
    app()->detectEnvironment(fn () => 'production');

    // Called directly: db:seed would ask for confirmation in production
    (new AdminSeeder)->run();
    $hash = User::where('username', 'admin')->value('password');

    expect(Hash::check('admin123', $hash))->toBeFalse();

    (new AdminSeeder)->run();
    expect(User::where('username', 'admin')->value('password'))->toBe($hash);
});
