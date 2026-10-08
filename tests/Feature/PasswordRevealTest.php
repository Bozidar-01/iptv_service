<?php

use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('keeps an encrypted copy of the password and shows it on the edit page', function () {
    $admin = User::factory()->admin()->create();
    $user = app(UserService::class)->createUser(['username' => 'sub1', 'password' => 'secret123']);

    expect(Hash::check('secret123', $user->password))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('password_plain'))->not->toContain('secret123')
        ->and($user->fresh()->password_plain)->toBe('secret123');

    $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->assertSee('secret123');

    app(UserService::class)->updateUser($user, ['password' => 'newpass99']);
    expect($user->fresh()->password_plain)->toBe('newpass99');

    app(UserService::class)->updateUser($user, ['password' => '']);
    expect($user->fresh()->password_plain)->toBe('newpass99');
});

it('says so when no copy of the password is stored', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->assertSee('not stored for this user');
});
