<?php

use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reseller = User::factory()->create(['role' => 'reseller']);
    $this->user = User::factory()->create([
        'username' => 'marko', 'owner_id' => $this->reseller->id, 'password_plain' => 'tv-pass-9',
        'expiration_date' => Carbon::parse('2026-12-05'), 'locale' => null,
    ]);
});

it('gives the admin a message to paste to the user', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))->assertOk();

    $response->assertSee('Message for the user')
        ->assertSeeInOrder(['Server URL: http://localhost', 'Username: marko', 'Password: tv-pass-9', 'Playlist link: '.route('playlist', $this->user->playlist_token), 'Your page (expiry and renewal): '.route('me', $this->user->playlist_token), 'Valid until: 5 December 2026'], false);
});

it('gives a reseller the same for their own user, and nothing for someone else\'s', function () {
    $this->actingAs($this->reseller)->get(route('admin.users.show', $this->user))->assertSee('Password: tv-pass-9');

    $stranger = User::factory()->create(['role' => 'reseller']);
    $this->actingAs($stranger)->get(route('admin.users.show', $this->user))->assertNotFound();
});

it('leaves the password out when it is not stored, and the date out when there is none', function () {
    $this->user->forceFill(['password_plain' => null, 'expiration_date' => null])->save();

    $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))
        ->assertSee('Username: marko')->assertDontSee('Password: ')->assertDontSee('Valid until')
        ->assertSee('The password is not in the message');
});

it('is written in the user\'s own language and the panel language is left alone', function () {
    $this->user->update(['locale' => 'mk']);

    $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))
        ->assertSee('Корисничко име: marko')->assertSee('Лозинка: tv-pass-9')->assertSee('Важи до: 5 декември 2026')
        // the rest of the page stays in English
        ->assertSee('Recent activity');
});
