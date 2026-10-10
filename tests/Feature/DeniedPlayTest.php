<?php

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\DeniedPlay;
use App\Models\User;
use App\Services\StreamService;
use App\Services\TokenService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reseller = User::factory()->create(['role' => 'reseller']);
    $this->other = User::factory()->create(['role' => 'reseller']);
    $this->user = User::factory()->create(['username' => 'maria', 'owner_id' => $this->reseller->id, 'max_connections' => 1]);
    $this->channel = subscribedChannel($this->user, ['name' => 'Rai 1']);
    $this->url = fn () => '/live/maria/password/'.$this->channel->id.'.ts';
});

function reasonFor(User $user, Channel $channel): ?string
{
    return app(StreamService::class)->denialReason($user->fresh(), $channel->fresh());
}

describe('the reasons', function () {
    it('names why a play is refused', function () {
        expect(reasonFor($this->user, $this->channel))->toBeNull();

        $this->channel->update(['is_active' => false]);
        expect(reasonFor($this->user, $this->channel))->toBe(DeniedPlay::CHANNEL_OFF);
        $this->channel->update(['is_active' => true]);

        $this->user->update(['expiration_date' => now()->subDay()]);
        expect(reasonFor($this->user, $this->channel))->toBe(DeniedPlay::EXPIRED);
        $this->user->update(['expiration_date' => null, 'status' => 'disabled']);
        expect(reasonFor($this->user, $this->channel))->toBe(DeniedPlay::DISABLED);
    });

    it('tells a hidden channel from one that is not in their bouquets', function () {
        $this->user->hiddenChannels()->attach($this->channel);
        expect(reasonFor($this->user, $this->channel))->toBe(DeniedPlay::HIDDEN);

        $this->user->hiddenChannels()->detach();
        $other = Channel::create(['name' => 'Other', 'stream_url' => 'http://x.test/o', 'is_active' => true]);
        Bouquet::create(['name' => 'Elsewhere'])->channels()->attach($other);
        expect(reasonFor($this->user, $other))->toBe(DeniedPlay::NOT_IN_BOUQUET);
    });

    it('sees the connection limit and a disconnected device', function () {
        // another device is playing
        $this->user->activeSessions()->create(['channel_id' => $this->channel->id, 'ip' => '9.9.9.9', 'user_agent' => 'Other', 'started_at' => now(), 'last_activity_at' => now()]);
        expect(reasonFor($this->user, $this->channel))->toBe(DeniedPlay::LIMIT);

        // A device an admin disconnected is kept out for a while: play once, disconnect it, play again
        $this->user->activeSessions()->delete();
        $this->get(($this->url)())->assertRedirect();
        app(StreamService::class)->disconnect($this->user->activeSessions()->sole());
        $this->get(($this->url)())->assertForbidden();
        expect(DeniedPlay::sole()->reason)->toBe(DeniedPlay::BLOCKED);
    });
});

describe('recording', function () {
    it('records a refused play once a minute, with the reason', function () {
        $this->user->update(['status' => 'disabled']);

        $this->get(($this->url)())->assertForbidden();
        $this->get(($this->url)())->assertForbidden();

        $row = DeniedPlay::sole();
        expect($row->user_id)->toBe($this->user->id)->and($row->channel_id)->toBe($this->channel->id)->and($row->reason)->toBe(DeniedPlay::DISABLED)
            ->and($row->label())->toBe('Account disabled')->and($row->hint())->toBe('Enable the user.');

        $this->travel(61)->seconds();
        $this->get(($this->url)())->assertForbidden();
        expect(DeniedPlay::count())->toBe(2);
    });

    it('records nothing for a play that is allowed, and not for a wrong password', function () {
        $this->get(($this->url)())->assertRedirect();
        $this->get('/live/maria/wrong/'.$this->channel->id.'.ts')->assertUnauthorized();

        expect(DeniedPlay::count())->toBe(0);
    });

    it('records refusals from the play link too', function () {
        $token = app(TokenService::class)->generateToken($this->user)->token;
        $this->channel->update(['is_active' => false]);

        $this->get(route('play', ['channel_id' => $this->channel->id, 'token' => $token]))->assertForbidden();

        expect(DeniedPlay::sole()->reason)->toBe(DeniedPlay::CHANNEL_OFF);
    });

    it('is pruned after the retention days', function () {
        config(['iptv.log_retention_days' => 30]);
        DeniedPlay::create(['user_id' => $this->user->id, 'reason' => 'expired'])->forceFill(['created_at' => now()->subDays(31)])->save();
        DeniedPlay::create(['user_id' => $this->user->id, 'reason' => 'expired']);

        (new DeniedPlay)->prunable()->delete();

        expect(DeniedPlay::count())->toBe(1);
    });
});

describe('the pages', function () {
    beforeEach(function () {
        $this->mine = DeniedPlay::create(['user_id' => $this->user->id, 'channel_id' => $this->channel->id, 'reason' => DeniedPlay::LIMIT, 'ip' => '1.2.3.4', 'user_agent' => 'VLC']);
        $stranger = User::factory()->create(['username' => 'zed', 'owner_id' => $this->other->id]);
        DeniedPlay::create(['user_id' => $stranger->id, 'reason' => DeniedPlay::EXPIRED]);
    });

    it('lists refusals with the reason and what to do, scoped to the reseller', function () {
        $this->actingAs($this->admin)->get(route('admin.logs.refused'))->assertOk()->assertSee('maria')->assertSee('zed')->assertSee('Too many devices at once')->assertSee('raise Streams at once', false);
        $this->actingAs($this->reseller)->get(route('admin.logs.refused'))->assertSee('maria')->assertDontSee('zed');
    });

    it('filters by reason and user', function () {
        // maria's row has the address 1.2.3.4 (names also appear in the filter menus)
        $this->actingAs($this->admin)->get(route('admin.logs.refused', ['reason' => 'expired']))->assertDontSee('1.2.3.4');
        $this->actingAs($this->admin)->get(route('admin.logs.refused', ['reason' => 'connection_limit']))->assertSee('1.2.3.4');
        $this->actingAs($this->admin)->get(route('admin.logs.refused', ['user' => $this->user->id]))->assertSee('1.2.3.4');
        $this->actingAs($this->admin)->get(route('admin.logs.refused', ['reason' => 'nonsense']))->assertOk()->assertSee('1.2.3.4');
    });

    it('shows the latest refusals on the user page, and has tabs on Activity', function () {
        $this->actingAs($this->reseller)->get(route('admin.users.show', $this->user))->assertSee('Refused plays')->assertSee('Rai 1')->assertSee('Too many devices at once');
        $this->actingAs($this->admin)->get(route('admin.logs.index'))->assertSee(route('admin.logs.refused'), false);
    });

    it('is for staff only', function () {
        auth()->logout();
        $this->get(route('admin.logs.refused'))->assertRedirect(route('login'));
        $this->actingAs($this->user)->get(route('admin.logs.refused'))->assertRedirect(route('login'));
    });
});
