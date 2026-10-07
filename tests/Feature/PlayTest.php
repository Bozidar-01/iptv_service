<?php

use App\Models\AccessToken;
use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\TokenService;

function tokenFor(User $user): string
{
    return app(TokenService::class)->generateToken($user)->token;
}

it('redirects to the stream and records the play', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user);

    $this->get(route('play', ['channel_id' => $channel->id, 'token' => tokenFor($user)]))
        ->assertRedirect($channel->stream_url);

    expect(StreamLog::where('user_id', $user->id)->where('channel_id', $channel->id)->count())->toBe(1)
        ->and(ActiveSession::where('user_id', $user->id)->where('channel_id', $channel->id)->count())->toBe(1);
});

it('rejects an unknown token', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user);

    $this->get(route('play', ['channel_id' => $channel->id, 'token' => 'nope']))->assertStatus(401);
});

it('rejects an expired token', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user);
    $token = AccessToken::create(['user_id' => $user->id, 'token' => 'old-token', 'expires_at' => now()->subMinute()]);

    $this->get(route('play', ['channel_id' => $channel->id, 'token' => $token->token]))->assertStatus(401);
});

it('returns 404 for an unknown channel', function () {
    $user = User::factory()->create();

    $this->get(route('play', ['channel_id' => 999, 'token' => tokenFor($user)]))->assertStatus(404);
});

it('denies disabled and expired users', function (string $state) {
    $user = User::factory()->{$state}()->create();
    $channel = subscribedChannel($user);

    $this->get(route('play', ['channel_id' => $channel->id, 'token' => tokenFor($user)]))->assertStatus(403);
})->with(['disabled', 'expired']);

it('denies inactive channels', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user, ['is_active' => false]);

    $this->get(route('play', ['channel_id' => $channel->id, 'token' => tokenFor($user)]))->assertStatus(403);
});

it('denies channels outside the user bouquets', function () {
    $user = User::factory()->create();
    $channel = Channel::create(['name' => 'Other', 'stream_url' => 'https://upstream.test/x', 'is_active' => true]);

    $this->get(route('play', ['channel_id' => $channel->id, 'token' => tokenFor($user)]))->assertStatus(403);
});

it('enforces the connection limit for a second device on a different channel', function () {
    $user = User::factory()->create(['max_connections' => 1]);
    $first = subscribedChannel($user);
    $second = subscribedChannel($user);
    $token = tokenFor($user);

    $this->get(route('play', ['channel_id' => $first->id, 'token' => $token]))->assertRedirect();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->get(route('play', ['channel_id' => $second->id, 'token' => $token]))->assertStatus(403);
});

it('lets a device switch channels at the limit and replaces its session', function () {
    $user = User::factory()->create(['max_connections' => 1]);
    $first = subscribedChannel($user);
    $second = subscribedChannel($user);
    $token = tokenFor($user);

    $this->get(route('play', ['channel_id' => $first->id, 'token' => $token]))->assertRedirect();
    $this->get(route('play', ['channel_id' => $second->id, 'token' => $token]))->assertRedirect($second->stream_url);

    expect(ActiveSession::where('user_id', $user->id)->pluck('channel_id')->all())->toBe([$second->id]);

    // The slot is still taken, so another device is denied
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->get(route('play', ['channel_id' => $first->id, 'token' => $token]))->assertStatus(403);
});

it('counts a second device in the same household against the limit, even on the same channel', function () {
    $user = User::factory()->create(['max_connections' => 1]);
    $first = subscribedChannel($user);
    $second = subscribedChannel($user);
    $token = tokenFor($user);
    $livingRoom = ['HTTP_USER_AGENT' => 'TiviMate/5.0 (Android TV)'];
    $bedroom = ['HTTP_USER_AGENT' => 'IPTV Smarters/3.1 (Fire TV)'];

    $this->withServerVariables($livingRoom)
        ->get(route('play', ['channel_id' => $first->id, 'token' => $token]))->assertRedirect();
    $this->withServerVariables($bedroom)
        ->get(route('play', ['channel_id' => $second->id, 'token' => $token]))->assertStatus(403);
    $this->withServerVariables($bedroom)
        ->get(route('play', ['channel_id' => $first->id, 'token' => $token]))->assertStatus(403);

    expect(ActiveSession::count())->toBe(1);
});

it('allows two devices with a limit of 2', function () {
    $user = User::factory()->create(['max_connections' => 2]);
    $channel = subscribedChannel($user);
    $token = tokenFor($user);

    foreach (['TV A', 'TV B'] as $agent) {
        $this->withServerVariables(['HTTP_USER_AGENT' => $agent])
            ->get(route('play', ['channel_id' => $channel->id, 'token' => $token]))->assertRedirect();
    }
    $this->withServerVariables(['HTTP_USER_AGENT' => 'TV C'])
        ->get(route('play', ['channel_id' => $channel->id, 'token' => $token]))->assertStatus(403);
});

it('allows re-requesting the channel already being watched at the limit', function () {
    $user = User::factory()->create(['max_connections' => 1]);
    $channel = subscribedChannel($user);
    $token = tokenFor($user);

    $this->get(route('play', ['channel_id' => $channel->id, 'token' => $token]))->assertRedirect();
    $this->get(route('play', ['channel_id' => $channel->id, 'token' => $token]))->assertRedirect();
});

it('frees the slot once a session is older than the timeout', function () {
    $user = User::factory()->create(['max_connections' => 1]);
    $first = subscribedChannel($user);
    $second = subscribedChannel($user);
    $token = tokenFor($user);

    $this->get(route('play', ['channel_id' => $first->id, 'token' => $token]))->assertRedirect();
    $this->travel(6)->minutes();
    $this->get(route('play', ['channel_id' => $second->id, 'token' => $token]))->assertRedirect();
});

it('does not let a stale same-channel session bypass the limit', function () {
    $user = User::factory()->create(['max_connections' => 1]);
    $stale = subscribedChannel($user);
    $current = subscribedChannel($user);
    ActiveSession::create([
        'user_id' => $user->id, 'channel_id' => $stale->id,
        'started_at' => now()->subHour(), 'last_activity_at' => now()->subHour(),
    ]);
    $token = tokenFor($user);

    $this->get(route('play', ['channel_id' => $current->id, 'token' => $token]))->assertRedirect();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->get(route('play', ['channel_id' => $stale->id, 'token' => $token]))->assertStatus(403);
});

it('keeps started_at while a session stays active and resets it after it goes stale', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user);
    $token = tokenFor($user);
    $url = route('play', ['channel_id' => $channel->id, 'token' => $token]);

    $this->freezeSecond();
    $this->get($url);
    $startedAt = ActiveSession::first()->started_at;

    $this->travel(2)->minutes();
    $this->get($url);
    expect(ActiveSession::first()->started_at->equalTo($startedAt))->toBeTrue();

    $this->travel(10)->minutes();
    $this->get($url);
    expect(ActiveSession::first()->started_at->equalTo(now()))->toBeTrue();
});

it('prunes stale active sessions', function () {
    $user = User::factory()->create();
    $fresh = subscribedChannel($user);
    $stale = subscribedChannel($user);
    ActiveSession::create(['user_id' => $user->id, 'channel_id' => $fresh->id, 'last_activity_at' => now()]);
    ActiveSession::create(['user_id' => $user->id, 'channel_id' => $stale->id, 'last_activity_at' => now()->subHour()]);

    $this->artisan('model:prune', ['--model' => [ActiveSession::class]])->assertSuccessful();

    expect(ActiveSession::pluck('channel_id')->all())->toBe([$fresh->id]);
});

it('accepts play URLs with a stream extension', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user, ['stream_url' => 'http://p.test/live/1.ts']);

    $this->get('/play/'.$channel->id.'/'.tokenFor($user).'.ts')->assertRedirect($channel->stream_url);
});
