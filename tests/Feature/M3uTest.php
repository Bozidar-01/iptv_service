<?php

use App\Models\AccessToken;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\User;
use App\Services\TokenService;

it('returns a playlist with a play url per active subscribed channel', function () {
    $user = User::factory()->create();
    $sports = Category::create(['name' => 'Sports']);
    $active = subscribedChannel($user, ['name' => 'Sport 1', 'category_id' => $sports->id, 'logo_url' => 'https://logo.test/1.png']);
    subscribedChannel($user, ['name' => 'Hidden', 'is_active' => false]);

    $response = $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']));

    $response->assertOk()->assertHeader('Content-Type', 'audio/x-mpegurl');

    $body = $response->streamedContent();
    $token = AccessToken::where('user_id', $user->id)->value('token');

    expect($body)->toStartWith('#EXTM3U x-tvg-url="'.route('xtream.xmltv', ['username' => $user->username, 'password' => 'password']).'"')
        ->toContain('tvg-name="Sport 1" tvg-logo="https://logo.test/1.png" group-title="Sports",Sport 1')
        ->toContain(route('play', ['channel_id' => $active->id, 'token' => $token]))
        ->not->toContain('Hidden')
        ->and(substr_count($body, '/play/'))->toBe(1);
});

it('uses General as the group for channels without a category', function () {
    $user = User::factory()->create();
    subscribedChannel($user);

    $body = $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']))->streamedContent();

    expect($body)->toContain('group-title="General"');
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
    $category = Category::create(['name' => 'Kids "Fun"']);
    subscribedChannel($user, ['name' => "Say \"Hi\"\nTV", 'category_id' => $category->id]);

    $body = $this->get('/m3u?'.http_build_query(['username' => $user->username, 'password' => 'password']))->streamedContent();

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

it('serves the playlist from the per-user link', function () {
    $user = User::factory()->create();
    subscribedChannel($user, ['name' => 'Linked']);

    $response = $this->get(route('playlist', ['token' => $user->playlist_token]))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/x-mpegurl');
    expect($response->streamedContent())->toContain('Linked');

    $this->get(route('playlist', ['token' => 'unknown']))->assertNotFound();
});

it('refuses the playlist link for disabled users', function () {
    $user = User::factory()->disabled()->create();

    $this->get(route('playlist', ['token' => $user->playlist_token]))->assertStatus(403);
});

it('lists a channel once even when it is in several bouquets', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user);
    $second = Bouquet::create(['name' => 'Second']);
    $second->channels()->attach($channel);
    $user->bouquets()->attach($second);

    $body = $this->get(route('playlist', ['token' => $user->playlist_token]))->streamedContent();

    expect(substr_count($body, '/play/'))->toBe(1);
});

it('orders channels by category then source order and keeps the stream extension', function () {
    $user = User::factory()->create();
    $b = Category::create(['name' => 'B group']);
    $a = Category::create(['name' => 'A group']);
    subscribedChannel($user, ['name' => 'Second', 'category_id' => $b->id, 'stream_url' => 'http://p.test/live/2.ts']);
    subscribedChannel($user, ['name' => 'First', 'category_id' => $a->id, 'stream_url' => 'http://p.test/hls/1.m3u8']);

    $body = $this->get(route('playlist', ['token' => $user->playlist_token]))->streamedContent();

    expect(strpos($body, ',First'))->toBeLessThan(strpos($body, ',Second'))
        ->and($body)->toMatch('#/play/\d+/[A-Za-z0-9]{32}\.m3u8\n#')
        ->and($body)->toMatch('#/play/\d+/[A-Za-z0-9]{32}\.ts\n#');
});

it('issues a fresh playback token when the current one expires within a week', function () {
    $user = User::factory()->create();
    subscribedChannel($user);
    AccessToken::create(['user_id' => $user->id, 'token' => 'almost-expired', 'expires_at' => now()->addDays(2)]);

    $body = $this->get(route('playlist', ['token' => $user->playlist_token]))->streamedContent();

    expect($body)->not->toContain('almost-expired')
        ->and(AccessToken::where('user_id', $user->id)->count())->toBe(2);
});

it('streams a long playlist in chunks with one entry per channel', function () {
    $user = User::factory()->create();
    foreach (range(1, 1500) as $i) {
        subscribedChannel($user, ['name' => "Channel $i"]);
    }

    $chunks = 0;
    app(TokenService::class)->writeM3u($user, 'tok', null, function () use (&$chunks) {
        $chunks++;
    });
    $body = $this->get(route('playlist', ['token' => $user->playlist_token]))->streamedContent();

    expect($chunks)->toBeGreaterThan(2)
        ->and(substr_count($body, '#EXTINF'))->toBe(1500)
        ->and($body)->toStartWith('#EXTM3U');
});
