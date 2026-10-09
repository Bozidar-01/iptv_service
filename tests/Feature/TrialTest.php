<?php

use App\Models\Bouquet;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->bouquet = Bouquet::create(['name' => 'Sports']);
    $this->other = Bouquet::create(['name' => 'Movies']);
    $this->reseller = User::factory()->create(['role' => 'reseller', 'credits' => 5, 'max_customers' => null]);
    $this->reseller->bouquets()->attach($this->bouquet);
});

it('lets an admin make a trial with a random login, one device and a short end date', function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->actingAs($this->admin)->post(route('admin.users.trial.store'), ['hours' => 24, 'bouquets' => [$this->bouquet->id, $this->other->id]])
        ->assertRedirect();

    $trial = User::where('is_trial', true)->firstOrFail();
    expect($trial->username)->toMatch('/^trial\d{5}$/')
        ->and($trial->password_plain)->toMatch('/^[a-z0-9]{8}$/')
        ->and($trial->max_connections)->toBe(1)
        ->and($trial->role)->toBe('user')
        ->and($trial->owner_id)->toBeNull()
        ->and($trial->expiration_date->toDateTimeString())->toBe('2026-10-10 12:00:00')
        ->and($trial->bouquets->pluck('id')->sort()->values()->all())->toBe([$this->bouquet->id, $this->other->id]);

    $this->actingAs($this->admin)->get(route('admin.users.show', $trial))->assertSee('Trial')->assertSee('Message for the user');
});

it('makes a reseller\'s trial theirs, free, with only their own bouquets', function () {
    $this->actingAs($this->reseller)->post(route('admin.users.trial.store'), ['hours' => 72, 'bouquets' => [$this->bouquet->id]])->assertRedirect();

    $trial = User::where('is_trial', true)->firstOrFail();
    expect($trial->owner_id)->toBe($this->reseller->id)
        ->and($this->reseller->fresh()->credits)->toBe(5)
        ->and(CreditTransaction::count())->toBe(0);

    // A bouquet they do not hold is refused
    $this->actingAs($this->reseller)->post(route('admin.users.trial.store'), ['hours' => 24, 'bouquets' => [$this->other->id]])->assertSessionHasErrors('bouquets.0');
    expect(User::where('is_trial', true)->count())->toBe(1);
});

it('limits a reseller to a number of trials per day, and not an admin', function () {
    config(['iptv.trials_per_day' => 2]);
    $make = fn ($actor) => $this->actingAs($actor)->post(route('admin.users.trial.store'), ['hours' => 24, 'bouquets' => [$this->bouquet->id]]);

    $make($this->reseller);
    $make($this->reseller);
    $make($this->reseller)->assertSessionHas('error', 'You can make 2 trials per day. Try again tomorrow.');
    expect(User::where('owner_id', $this->reseller->id)->count())->toBe(2);

    // Yesterday's trials do not count
    Carbon::setTestNow(now()->addDay()->addMinute());
    $make($this->reseller)->assertSessionHasNoErrors();
    expect(User::where('owner_id', $this->reseller->id)->count())->toBe(3);

    foreach (range(1, 4) as $i) {
        $make($this->admin);
    }
    expect(User::whereNull('owner_id')->where('is_trial', true)->count())->toBe(4);
});

it('counts against a reseller\'s user limit', function () {
    $this->reseller->update(['max_customers' => 1]);
    User::factory()->create(['owner_id' => $this->reseller->id]);

    $this->actingAs($this->reseller)->post(route('admin.users.trial.store'), ['hours' => 24, 'bouquets' => [$this->bouquet->id]])
        ->assertSessionHas('error');

    expect(User::where('is_trial', true)->count())->toBe(0);
});

it('needs a bouquet and a known length', function () {
    $this->actingAs($this->admin)->post(route('admin.users.trial.store'), ['hours' => 24])->assertSessionHasErrors('bouquets');
    $this->actingAs($this->admin)->post(route('admin.users.trial.store'), ['hours' => 5, 'bouquets' => [$this->bouquet->id]])->assertSessionHasErrors('hours');
});

it('shows the form to staff only', function () {
    $this->actingAs($this->admin)->get(route('admin.users.trial'))->assertOk()->assertSee('Sports')->assertSee('Movies');
    $this->actingAs($this->reseller)->get(route('admin.users.trial'))->assertOk()->assertSee('Sports')->assertDontSee('Movies');

    auth()->logout();
    $this->get(route('admin.users.trial'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.users.trial'))->assertRedirect(route('login'));
});

it('stops being a trial when the end date is moved later', function () {
    $trial = app(UserService::class)->createTrial(null, 24, [$this->bouquet->id]);

    $this->actingAs($this->admin)->put(route('admin.users.update', $trial), [
        'username' => $trial->username, 'status' => 'active', 'max_connections' => 1, 'role' => 'user',
        'expiration_date' => now()->addMonth()->toDateString(), 'bouquets' => [$this->bouquet->id],
    ])->assertRedirect();

    expect($trial->fresh()->is_trial)->toBeFalse();
});

it('keeps a trial while the end date stays', function () {
    $trial = app(UserService::class)->createTrial(null, 24, [$this->bouquet->id]);

    $this->actingAs($this->admin)->put(route('admin.users.update', $trial), [
        'username' => $trial->username, 'status' => 'disabled', 'max_connections' => 1, 'role' => 'user',
        'expiration_date' => $trial->expiration_date->toDateString(), 'bouquets' => [$this->bouquet->id],
    ])->assertRedirect();

    expect($trial->fresh()->is_trial)->toBeTrue();
});

it('tells Xtream apps it is a trial', function () {
    $trial = app(UserService::class)->createTrial(null, 24, [$this->bouquet->id]);

    $this->getJson('/player_api.php?'.http_build_query(['username' => $trial->username, 'password' => $trial->password_plain]))
        ->assertJsonPath('user_info.is_trial', '1');
});
