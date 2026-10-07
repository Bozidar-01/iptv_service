<?php

use App\Models\AccessToken;
use App\Models\Category;
use App\Models\User;

it('returns a playlist with a play url per active subscribed channel', function () {
    $user = User::factory()->create();
    $sports = Category::create(['name' => 'Sports']);
    $active = subscribedChannel($user, ['name' => 'Sport 1', 'category_id' => $sports->id, 'logo_url' => 'https://logo.test/1.png']);
    subscribedChannel($user, ['name' => 'Hidden', 'is_active' => false]);

    $response = $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']));

    $response->assertOk()->assertHeader('Content-Type', 'audio/x-mpegurl');

    $body = $response->getContent();
    $token = AccessToken::where('user_id', $user->id)->value('token');

    expect($body)->toStartWith("#EXTM3U\n")
        ->toContain('tvg-name="Sport 1" tvg-logo="https://logo.test/1.png" group-title="Sports",Sport 1')
        ->toContain(route('play', ['channel_id' => $active->id, 'token' => $token]))
        ->not->toContain('Hidden')
        ->and(substr_count($body, '/play/'))->toBe(1);
});

it('uses General as the group for channels without a category', function () {
    $user = User::factory()->create();
    subscribedChannel($user);

    $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']))
        ->assertSee('group-title="General"', false);
});

it('reuses an existing valid token', function () {
    $user = User::factory()->create();
    subscribedChannel($user);
    $query = '/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']);

    $this->get($query)->assertOk();
    $this->get($query)->assertOk();

    expect(AccessToken::where('user_id', $user->id)->count())->toBe(1);
});

it('escapes quotes and line breaks in channel and category names', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => "Kids \"Fun\""]);
    subscribedChannel($user, ['name' => "Say \"Hi\"\nTV", 'category_id' => $category->id]);

    $body = $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']))->getContent();

    expect($body)->toContain("tvg-name=\"Say 'Hi' TV\"")
        ->toContain("group-title=\"Kids 'Fun'\",Say \"Hi\" TV\n")
        ->and(substr_count($body, "\n"))->toBe(3);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create();

    $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'nope']))
        ->assertStatus(401);
});

it('rejects disabled accounts', function () {
    $user = User::factory()->disabled()->create();

    $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']))
        ->assertStatus(403)
        ->assertSee('Account disabled');
});

it('rejects expired accounts with a distinct message', function () {
    $user = User::factory()->expired()->create();

    $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']))
        ->assertStatus(403)
        ->assertSee('Account expired');
});
