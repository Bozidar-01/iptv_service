<?php

use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\StreamService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->user = User::factory()->create(['max_connections' => 2]);
    $this->channel = subscribedChannel($this->user, ['name' => 'Arena']);
});

function watching(User $user, Channel $channel, string $ip = '10.0.0.5', string $agent = 'TiviMate/4.7', int $minutesAgo = 0): ActiveSession
{
    return ActiveSession::create([
        'user_id' => $user->id, 'channel_id' => $channel->id, 'ip' => $ip, 'user_agent' => $agent,
        'started_at' => now()->subMinutes($minutesAgo + 1), 'last_activity_at' => now()->subMinutes($minutesAgo),
    ]);
}

it('lists the devices that are watching right now, not the stale ones', function () {
    watching($this->user, $this->channel, '10.0.0.5', 'TiviMate/4.7');
    watching($this->user, $this->channel, '10.0.0.6', 'OldPlayer/1.0', minutesAgo: 20);

    $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))
        ->assertOk()
        ->assertSee('Devices watching now')
        ->assertSee('Arena')
        ->assertSee('10.0.0.5')
        ->assertSee('TiviMate/4.7')
        ->assertDontSee('OldPlayer/1.0');
});

it('says so when nobody is watching', function () {
    $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))->assertSee('Nobody is watching right now.');
});

it('disconnects a device and keeps it out for a while, but not other devices', function () {
    $mine = watching($this->user, $this->channel, '127.0.0.1', 'Symfony');
    $other = watching($this->user, $this->channel, '10.0.0.5', 'TiviMate/4.7');
    $token = tokenFor($this->user);

    $this->actingAs($this->admin)
        ->post(route('admin.users.sessions.disconnect', [$this->user, $mine]))
        ->assertRedirect()
        ->assertSessionHas('success');
    auth()->logout();

    expect(ActiveSession::find($mine->id))->toBeNull()->and(ActiveSession::find($other->id))->not->toBeNull();

    // The disconnected device (the test client) can't start a stream
    $this->get(route('play', ['channel_id' => $this->channel->id, 'token' => $token]))->assertStatus(403);
    expect(StreamLog::count())->toBe(0);

    // ...until the block runs out
    $this->travel(StreamService::DEVICE_BLOCK_MINUTES + 1)->minutes();
    $this->get(route('play', ['channel_id' => $this->channel->id, 'token' => $token]))->assertRedirect();
});

it('frees the slot of a disconnected device for another one', function () {
    $this->user->update(['max_connections' => 1]);
    $session = watching($this->user, $this->channel, '10.0.0.5', 'TiviMate/4.7');
    $token = tokenFor($this->user);

    // The only slot is taken by another device
    $this->get(route('play', ['channel_id' => $this->channel->id, 'token' => $token]))->assertStatus(403);

    $this->actingAs($this->admin)->post(route('admin.users.sessions.disconnect', [$this->user, $session]));
    auth()->logout();

    $this->get(route('play', ['channel_id' => $this->channel->id, 'token' => $token]))->assertRedirect();
});

it('can only disconnect a session that belongs to that user', function () {
    $stranger = User::factory()->create();
    $strangerChannel = subscribedChannel($stranger);
    $theirs = watching($stranger, $strangerChannel);

    $this->actingAs($this->admin)
        ->post(route('admin.users.sessions.disconnect', [$this->user, $theirs]))
        ->assertNotFound();

    expect(ActiveSession::find($theirs->id))->not->toBeNull();
});

it('disconnects all devices of a user at once', function () {
    watching($this->user, $this->channel, '10.0.0.5', 'A');
    watching($this->user, $this->channel, '10.0.0.6', 'B');
    $untouched = watching(User::factory()->create(), $this->channel, '10.0.0.7', 'C');

    $this->actingAs($this->admin)
        ->post(route('admin.users.sessions.disconnect-all', $this->user))
        ->assertSessionHas('success', fn ($message) => str_contains($message, '2 devices'));

    expect(ActiveSession::where('user_id', $this->user->id)->count())->toBe(0)
        ->and(ActiveSession::find($untouched->id))->not->toBeNull();
});

it('is for admins only', function () {
    $session = watching($this->user, $this->channel);

    $this->post(route('admin.users.sessions.disconnect', [$this->user, $session]))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->post(route('admin.users.sessions.disconnect', [$this->user, $session]))->assertRedirect();

    expect(ActiveSession::find($session->id))->not->toBeNull();
});
