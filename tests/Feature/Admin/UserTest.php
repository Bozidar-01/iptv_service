<?php

use App\Models\Bouquet;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('creates a user and lands on their playlist page', function () {
    $bouquet = Bouquet::create(['name' => 'Pack']);

    $response = $this->post(route('admin.users.store'), [
        'username' => 'livingroom', 'password' => 'secret1', 'role' => 'user', 'status' => 'active',
        'expiration_date' => now()->addMonth()->toDateString(), 'max_connections' => 2, 'bouquets' => [$bouquet->id],
    ]);

    $user = User::where('username', 'livingroom')->firstOrFail();
    $response->assertRedirect(route('admin.users.show', $user));
    expect($user->playlist_token)->toHaveLength(32)
        ->and($user->bouquets()->pluck('name')->all())->toBe(['Pack']);
});

it('shows the playlist link and TV instructions', function () {
    $user = User::factory()->create();

    $this->get(route('admin.users.show', $user))
        ->assertOk()
        ->assertSee(route('playlist', ['token' => $user->playlist_token]))
        ->assertSee('TiviMate')
        ->assertSee('The playlist is empty');
});

it('warns when the panel is opened on localhost', function () {
    $user = User::factory()->create();

    $this->get('http://localhost/admin/users/'.$user->id)->assertSee("your TV can't reach", false);
});

it('regenerates the playlist link', function () {
    $user = User::factory()->create();
    $old = $user->playlist_token;

    $this->post(route('admin.users.playlist-token', $user))->assertSessionHas('success');

    expect($user->fresh()->playlist_token)->not->toBe($old);
    $this->get(route('playlist', ['token' => $old]))->assertNotFound();
});

it('clears all bouquets when none are checked', function () {
    $user = User::factory()->create();
    $user->bouquets()->attach(Bouquet::create(['name' => 'Pack']));

    $this->put(route('admin.users.update', $user), [
        'username' => $user->username, 'role' => 'user', 'status' => 'active', 'max_connections' => 1,
    ])->assertRedirect(route('admin.users.show', $user));

    expect($user->bouquets()->count())->toBe(0);
});

it('filters users', function () {
    User::factory()->create(['username' => 'alice']);
    User::factory()->expired()->create(['username' => 'bob']);

    $this->get(route('admin.users.index', ['status' => 'expired']))->assertSee('bob')->assertDontSee('alice');
    $this->get(route('admin.users.index', ['search' => 'ali']))->assertSee('alice')->assertDontSee('bob');
});

it('does not let an admin delete their own account', function () {
    $this->delete(route('admin.users.destroy', $this->admin))->assertSessionHas('error');

    expect($this->admin->fresh())->not->toBeNull();
});

it('renders the dashboard with data', function () {
    $user = User::factory()->create(['expiration_date' => now()->addDays(3)]);

    $this->get(route('admin.dashboard'))->assertOk()->assertSee('Expiring within 7 days')->assertSee($user->username);
});
