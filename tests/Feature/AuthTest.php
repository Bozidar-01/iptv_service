<?php

use App\Models\User;

it('logs an admin into the dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->post('/login', ['username' => $admin->username, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($admin);
});

it('refuses panel login for subscribers', function () {
    $user = User::factory()->create();

    $this->post('/login', ['username' => $user->username, 'password' => 'password'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects wrong credentials', function () {
    $admin = User::factory()->admin()->create();

    $this->post('/login', ['username' => $admin->username, 'password' => 'wrong'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('redirects guests away from the admin panel', function () {
    $this->get('/admin/dashboard')->assertRedirect(route('login'));
});

it('shows the NeboTV name and icons on the sign-in page and panel', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<title>Sign in · NeboTV</title>', false)
        ->assertSee('Nebo<span class="brand-accent">TV</span>', false)
        ->assertSee('favicon.svg')
        ->assertSee('site.webmanifest');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('· NeboTV</title>', false)
        ->assertSee('apple-touch-icon.png');
});
