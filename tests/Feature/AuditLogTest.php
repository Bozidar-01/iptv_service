<?php

use App\Models\AuditLog;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Source;
use App\Models\User;
use App\Services\AuditService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->sports = Bouquet::create(['name' => 'Sports']);
    $this->movies = Bouquet::create(['name' => 'Movies']);
    $this->reseller = User::factory()->create(['username' => 'resA', 'role' => 'reseller', 'credits' => 10]);
    $this->reseller->bouquets()->attach([$this->sports->id, $this->movies->id]);
    $this->customer = User::factory()->create(['username' => 'cust1', 'owner_id' => $this->reseller->id, 'expiration_date' => now()->addDays(10)->startOfDay(), 'status' => 'active']);
    $this->customer->bouquets()->attach($this->sports);
});

function lastAudit(): AuditLog
{
    return AuditLog::latest('id')->firstOrFail();
}

function userForm(User $user, array $over = []): array
{
    return array_merge([
        'username' => $user->username, 'status' => $user->status, 'max_connections' => $user->max_connections, 'role' => $user->role,
        'expiration_date' => $user->expiration_date?->toDateString(), 'bouquets' => $user->bouquets->pluck('id')->all(),
    ], $over);
}

describe('users', function () {
    it('records who created a user, and what it cost', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.store'), [
            'username' => 'fresh', 'password' => 'secret1', 'status' => 'active', 'max_connections' => 1,
            'expiration_date' => now()->addMonth()->toDateString(), 'bouquets' => [$this->sports->id],
        ])->assertRedirect();

        $entry = lastAudit();
        expect($entry->action)->toBe(AuditLog::USER_CREATED)
            ->and($entry->actor_name)->toBe('resA')->and($entry->actor_id)->toBe($this->reseller->id)
            ->and($entry->subject_name)->toBe('fresh')
            ->and($entry->details['bouquets'])->toBe(['Sports'])
            ->and($entry->details['credits_spent'])->toBe(1)
            ->and($entry->summary())->toContain('Bouquets: Sports')->toContain('1 credit used');
    });

    it('records what changed on an update, never the password itself', function () {
        $new = now()->addDays(40)->toDateString();
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->customer), userForm($this->customer, [
            'status' => 'disabled', 'expiration_date' => $new, 'password' => 'brand-new-pass', 'bouquets' => [$this->movies->id],
        ]))->assertRedirect();

        $entry = lastAudit();
        expect($entry->action)->toBe(AuditLog::USER_UPDATED)
            ->and($entry->details['changes']['status'])->toBe(['active', 'disabled'])
            ->and($entry->details['changes']['expires'][1])->toStartWith($new)
            ->and($entry->details['password'])->toBeTrue()
            ->and($entry->details['bouquets_added'])->toBe(['Movies'])
            ->and($entry->details['bouquets_removed'])->toBe(['Sports'])
            ->and(json_encode($entry->details))->not->toContain('brand-new-pass');

        expect($entry->summary())->toContain('Status: active → disabled')->toContain('Password changed')->toContain('Bouquets added: Movies');
    });

    it('records nothing when a save changes nothing', function () {
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->customer), userForm($this->customer))->assertRedirect();

        expect(AuditLog::count())->toBe(0);
    });

    it('records deleting, disabling, a new link and disconnecting', function () {
        $this->actingAs($this->admin)->post(route('admin.users.toggle', $this->customer));
        expect(lastAudit()->action)->toBe(AuditLog::USER_DISABLED);

        $this->actingAs($this->admin)->post(route('admin.users.toggle', $this->customer));
        expect(lastAudit()->action)->toBe(AuditLog::USER_ENABLED);

        $this->actingAs($this->admin)->post(route('admin.users.playlist-token', $this->customer));
        expect(lastAudit()->action)->toBe(AuditLog::USER_LINK);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->customer));
        $entry = lastAudit();
        expect($entry->action)->toBe(AuditLog::USER_DELETED)->and($entry->subject_name)->toBe('cust1')->and($entry->details['set']['reseller'])->toBe('resA');
    });

    it('records a trial', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.trial.store'), ['hours' => 24, 'bouquets' => [$this->sports->id]]);

        expect(lastAudit()->action)->toBe(AuditLog::USER_TRIAL)->and(lastAudit()->actor_name)->toBe('resA');
    });
});

describe('credits and channels', function () {
    it('records credits given, taken back and made unlimited', function () {
        $this->actingAs($this->admin)->post(route('admin.users.credits.store', $this->reseller), ['amount' => 5, 'note' => 'October']);
        expect(lastAudit()->action)->toBe(AuditLog::CREDITS_GIVEN)->and(lastAudit()->summary())->toBe('5 credits (October)');

        $this->actingAs($this->admin)->post(route('admin.users.credits.store', $this->reseller), ['amount' => -2]);
        expect(lastAudit()->action)->toBe(AuditLog::CREDITS_TAKEN);

        $this->actingAs($this->admin)->post(route('admin.users.credits.unlimited', $this->reseller));
        expect(lastAudit()->action)->toBe(AuditLog::CREDITS_UNLIMITED)->and(lastAudit()->subject_name)->toBe('resA');
    });

    it('records hiding and showing channels, and who it was for', function () {
        $channel = Channel::create(['name' => 'Alpha', 'stream_url' => 'http://up.test/a.ts', 'is_active' => true]);
        $this->sports->channels()->attach($channel);
        $post = fn ($actor, string $action, ?User $user = null) => $this->actingAs($actor)->post(
            $user ? route('admin.users.channels.bulk', $user) : route('admin.hide-channels.bulk'),
            ['action' => $action, 'scope' => 'selected', 'ids' => [$channel->id]]
        );

        $post($this->reseller, 'hide', $this->customer);
        expect(lastAudit()->action)->toBe(AuditLog::CHANNELS_HIDDEN)->and(lastAudit()->subject_name)->toBe('cust1');

        $post($this->reseller, 'hide');
        expect(lastAudit()->summary())->toBe('1 channel · all users of resA');

        $post($this->admin, 'hide');
        expect(lastAudit()->summary())->toContain('the admins\' own users');

        $post($this->reseller, 'show');
        expect(lastAudit()->action)->toBe(AuditLog::CHANNELS_SHOWN);

        // Nothing changed, nothing recorded
        $count = AuditLog::count();
        $post($this->reseller, 'show');
        expect(AuditLog::count())->toBe($count);
    });

    it('records deleting a source, a bouquet and channels', function () {
        $source = Source::create(['name' => 'Provider', 'type' => 'url', 'url' => 'http://p.test/a.m3u', 'channel_count' => 7]);
        $this->actingAs($this->admin)->delete(route('admin.sources.destroy', $source));
        expect(lastAudit()->action)->toBe(AuditLog::SOURCE_DELETED)->and(lastAudit()->subject_name)->toBe('Provider')->and(lastAudit()->details['count'])->toBe(7);

        $this->actingAs($this->admin)->delete(route('admin.bouquets.destroy', $this->movies));
        expect(lastAudit()->action)->toBe(AuditLog::BOUQUET_DELETED)->and(lastAudit()->subject_name)->toBe('Movies');

        $a = Channel::create(['name' => 'A', 'stream_url' => 'http://u.test/a', 'is_active' => true]);
        $b = Channel::create(['name' => 'B', 'stream_url' => 'http://u.test/b', 'is_active' => true]);
        $this->actingAs($this->admin)->delete(route('admin.channels.destroy', $a));
        expect(lastAudit()->action)->toBe(AuditLog::CHANNELS_DELETED)->and(lastAudit()->subject_name)->toBe('A');

        $this->actingAs($this->admin)->post(route('admin.channels.bulk'), ['action' => 'delete', 'scope' => 'selected', 'ids' => [$b->id]]);
        expect(lastAudit()->details['count'])->toBe(1);
    });
});

describe('the page', function () {
    it('is for admins only', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index'))->assertOk()->assertSee('Nothing recorded yet');
        $this->actingAs($this->reseller)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee(route('admin.audit.index'), false);
        $this->actingAs($this->reseller)->get(route('admin.dashboard'))->assertDontSee(route('admin.audit.index'), false);

        auth()->logout();
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
    });

    it('lists and filters entries, linking users that still exist', function () {
        $audit = app(AuditService::class);
        $audit->record(AuditLog::USER_CREATED, $this->customer, [], $this->reseller);
        $audit->record(AuditLog::CREDITS_GIVEN, $this->reseller, ['amount' => 3], $this->admin);
        $audit->record(AuditLog::USER_DELETED, ['type' => 'user', 'id' => 99999, 'name' => 'phantom'], [], $this->admin);

        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertSee('User created')->assertSee('Credits given')->assertSee('phantom')
            ->assertSee(route('admin.users.show', $this->customer), false)
            ->assertDontSee(route('admin.users.show', 99999), false);

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['actor' => 'resA']))->assertSee('cust1')->assertDontSee('phantom');
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['action' => AuditLog::CREDITS_GIVEN]))->assertSee('3 credits')->assertDontSee('phantom');
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['search' => 'phan']))->assertSee('phantom')->assertDontSee('3 credits');
    });

    it('keeps entries for a year', function () {
        app(AuditService::class)->record(AuditLog::USER_CREATED, $this->customer, [], $this->admin);
        AuditLog::query()->update(['created_at' => now()->subDays(AuditLog::KEEP_DAYS + 1)]);
        app(AuditService::class)->record(AuditLog::USER_CREATED, $this->customer, [], $this->admin);

        (new AuditLog)->prunable()->delete();

        expect(AuditLog::count())->toBe(1);
    });

    it('keeps the name when the person is deleted', function () {
        app(AuditService::class)->record(AuditLog::USER_CREATED, $this->customer, [], $this->reseller);
        $this->customer->delete();
        $this->reseller->delete();

        $entry = lastAudit();
        expect($entry->actor_id)->toBeNull()->and($entry->actor_name)->toBe('resA')->and($entry->subject_name)->toBe('cust1');
    });
});
