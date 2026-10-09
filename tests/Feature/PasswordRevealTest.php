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

describe('on the user page', function () {
    it('has the password hidden until shown, with show and copy buttons', function () {
        $admin = User::factory()->admin()->create();
        $user = app(UserService::class)->createUser(['username' => 'sub2', 'password' => 'secret123']);

        $page = $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk();

        $page->assertSee('id="xtream-password" type="password" value="secret123"', false)
            ->assertSee('data-reveal="#xtream-password"', false)
            ->assertSee('data-copy="#xtream-password"', false)
            ->assertSee('Show current password')
            ->assertSee(route('admin.users.edit', $user), false);
    });

    it('says so when no copy of the password is stored', function () {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('not stored for this user')
            ->assertDontSee('id="xtream-password"', false);
    });

    it('shows a reseller the password of their own users only', function () {
        $reseller = User::factory()->create(['role' => 'reseller', 'expiration_date' => null]);
        $mine = app(UserService::class)->createUser(['username' => 'mine', 'password' => 'mine-pass-1', 'owner_id' => $reseller->id]);
        $other = app(UserService::class)->createUser(['username' => 'other', 'password' => 'other-pass-1']);

        $this->actingAs($reseller)->get(route('admin.users.show', $mine))->assertOk()->assertSee('mine-pass-1');
        $this->get(route('admin.users.show', $other))->assertNotFound();
    });
});
