<?php

use App\Models\User;

it('follows the system theme by default', function () {
    $this->get('/login')
        ->assertSee('data-theme="system"', false)
        ->assertSee('value="system" title="System" aria-label="System" aria-pressed="true"', false);
});

it('remembers light or dark mode in an unencrypted cookie', function () {
    $this->from('/login')
        ->post(route('theme.update'), ['theme' => 'dark'])
        ->assertRedirect('/login')
        ->assertPlainCookie('theme', 'dark');
});

it('forgets the cookie when switching back to system', function () {
    $response = $this->withUnencryptedCookie('theme', 'dark')
        ->from('/login')
        ->post(route('theme.update'), ['theme' => 'system']);

    expect($response->getCookie('theme', false)->isCleared())->toBeTrue();
});

it('renders the chosen theme on admin pages', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->withUnencryptedCookie('theme', 'light')
        ->get(route('admin.dashboard'))
        ->assertSee('data-theme="light"', false)
        ->assertSee('value="light" title="Light" aria-label="Light" aria-pressed="true"', false);
});

it('ignores unknown theme values', function () {
    $this->post(route('theme.update'), ['theme' => 'neon'])
        ->assertSessionHasErrors('theme')
        ->assertCookieMissing('theme');

    $this->withUnencryptedCookie('theme', 'neon')
        ->get('/login')
        ->assertSee('data-theme="system"', false);
});
