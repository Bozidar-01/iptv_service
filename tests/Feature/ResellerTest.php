<?php

use App\Models\ActiveSession;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\LoginEvent;
use App\Models\StreamAlert;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'boss']);

    $this->sports = Bouquet::create(['name' => 'Sports']);
    $this->movies = Bouquet::create(['name' => 'Movies']);
    $this->kids = Bouquet::create(['name' => 'Kids']);

    $this->resellerA = User::factory()->create(['username' => 'resA', 'role' => 'reseller', 'max_customers' => 2, 'max_customer_connections' => 2, 'expiration_date' => null]);
    $this->resellerA->bouquets()->attach([$this->sports->id, $this->movies->id]);
    $this->resellerB = User::factory()->create(['username' => 'resB', 'role' => 'reseller', 'expiration_date' => null]);

    $this->a1 = User::factory()->create(['username' => 'custA1', 'owner_id' => $this->resellerA->id]);
    $this->a2 = User::factory()->create(['username' => 'custA2', 'owner_id' => $this->resellerA->id]);
    $this->b1 = User::factory()->create(['username' => 'custB1', 'owner_id' => $this->resellerB->id]);
    $this->own = User::factory()->create(['username' => 'adminsCustomer']);
});

function customerPayload(array $over = []): array
{
    return array_merge(['username' => 'newcust', 'password' => 'secret1', 'status' => 'active', 'max_connections' => 1], $over);
}

describe('signing in', function () {
    it('lets a reseller into the panel, on their own dashboard', function () {
        $this->post('/login', ['username' => 'resA', 'password' => 'password'])->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Your users')->assertSee('You have 2 of 2 users.')->assertDontSee('Add source');
    });

    it('refuses a reseller who is switched off or has expired', function () {
        $this->resellerA->update(['status' => 'disabled']);
        $this->post('/login', ['username' => 'resA', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->resellerA->update(['status' => 'active', 'expiration_date' => now()->subDay()]);
        $this->post('/login', ['username' => 'resA', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->assertGuest();
    });

    it('signs a reseller out as soon as the admin switches them off', function () {
        $this->actingAs($this->resellerA)->get(route('admin.users.index'))->assertOk();

        $this->resellerA->update(['status' => 'disabled']);

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('works with two-factor sign-in like an admin', function () {
        [$reseller, $secret] = (function () {
            $service = app(TwoFactorService::class);
            $secret = $service->begin($this->resellerA);
            $service->confirm($this->resellerA, Totp::code($secret, Totp::step()));
            $this->resellerA->forceFill(['two_factor_last_step' => Totp::step() - 5])->save();

            return [$this->resellerA->fresh(), $secret];
        })();

        $this->post('/login', ['username' => 'resA', 'password' => 'password'])->assertRedirect(route('two-factor.show'));
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => Totp::code($secret, Totp::step())])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($reseller);
    });
});

describe('what a reseller may open', function () {
    it('gets a 403 on everything that is for admins only, and stays signed in', function (string $route) {
        $this->actingAs($this->resellerA)->get(route($route))->assertForbidden();

        $this->assertAuthenticatedAs($this->resellerA);
    })->with(['admin.sources.index', 'admin.sources.create', 'admin.channels.index', 'admin.categories.index', 'admin.bouquets.index', 'admin.epg.index', 'admin.statistics.index']);

    it('can not post to the admin-only actions either', function () {
        $this->actingAs($this->resellerA);

        $this->post(route('admin.channels.bulk'), ['action' => 'delete'])->assertForbidden();
        $this->post(route('admin.categories.store'), ['name' => 'X'])->assertForbidden();
        $this->post(route('admin.bouquets.store'), ['name' => 'X'])->assertForbidden();
    });

    it('sees only its own menu', function () {
        $this->actingAs($this->resellerA)->get(route('admin.dashboard'))
            ->assertSee('Users')->assertSee('Activity')->assertSee('Alerts')->assertSee('Security')
            ->assertDontSee(route('admin.sources.index'), false)
            ->assertDontSee(route('admin.statistics.index'), false)
            ->assertSee('Reseller');

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertSee(route('admin.sources.index'), false)
            ->assertSee(route('admin.statistics.index'), false);
    });
});

describe('users', function () {
    it('lists only the reseller\'s own users', function () {
        $this->actingAs($this->resellerA)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('custA1')->assertSee('custA2')
            ->assertDontSee('custB1')->assertDontSee('adminsCustomer')->assertDontSee('resB')->assertDontSee('boss');

        // searching for someone else's user finds nothing (the search box itself repeats the word, so look for the link)
        $this->get(route('admin.users.index', ['search' => 'custB1']))->assertDontSee(route('admin.users.show', $this->b1), false);
    });

    it('shows an admin everyone, with the reseller of each user', function () {
        $this->actingAs($this->admin)->get(route('admin.users.index'))
            ->assertSee('custA1')->assertSee('custB1')->assertSee('adminsCustomer')->assertSee('resA')->assertSee('Reseller: resA');

        $this->get(route('admin.users.index', ['reseller' => $this->resellerA->id]))
            ->assertSee('custA1')->assertDontSee('custB1')->assertDontSee('adminsCustomer');
    });

    it('answers 404 for every user that is not the reseller\'s, on every route', function (string $who) {
        $target = ['b1' => $this->b1, 'own' => $this->own, 'resB' => $this->resellerB, 'admin' => $this->admin][$who];
        $this->actingAs($this->resellerA);

        $this->get(route('admin.users.show', $target))->assertNotFound();
        $this->get(route('admin.users.edit', $target))->assertNotFound();
        $this->put(route('admin.users.update', $target), customerPayload(['username' => $target->username]))->assertNotFound();
        $this->delete(route('admin.users.destroy', $target))->assertNotFound();
        $this->post(route('admin.users.toggle', $target))->assertNotFound();
        $this->post(route('admin.users.playlist-token', $target))->assertNotFound();
        $this->post(route('admin.users.sessions.disconnect-all', $target))->assertNotFound();

        $fresh = $target->fresh();
        expect($fresh)->not->toBeNull()
            ->and($fresh->status)->toBe($target->status)
            ->and($fresh->playlist_token)->toBe($target->playlist_token);
    })->with(['b1', 'own', 'resB', 'admin']);

    it('can not end a device session of someone else\'s user', function () {
        $session = ActiveSession::create(['user_id' => $this->b1->id, 'channel_id' => subscribedChannel($this->b1)->id, 'ip' => '10.0.0.1', 'user_agent' => 'X', 'started_at' => now(), 'last_activity_at' => now()]);

        $this->actingAs($this->resellerA)->post(route('admin.users.sessions.disconnect', [$this->b1, $session]))->assertNotFound();
        // ... nor by pairing it with one of its own users
        $this->post(route('admin.users.sessions.disconnect', [$this->a1, $session]))->assertNotFound();

        expect(ActiveSession::find($session->id))->not->toBeNull();
    });

    it('manages its own users', function () {
        $this->actingAs($this->resellerA);

        $this->get(route('admin.users.show', $this->a1))->assertOk()->assertSee('custA1');
        $this->get(route('admin.users.edit', $this->a1))->assertOk();
        $this->post(route('admin.users.toggle', $this->a1))->assertRedirect();
        expect($this->a1->fresh()->status)->toBe('disabled');

        $old = $this->a1->playlist_token;
        $this->post(route('admin.users.playlist-token', $this->a1));
        expect($this->a1->fresh()->playlist_token)->not->toBe($old);

        $this->delete(route('admin.users.destroy', $this->a2))->assertRedirect(route('admin.users.index'));
        expect(User::find($this->a2->id))->toBeNull();
    });
});

describe('creating users', function () {
    it('offers only the reseller\'s own bouquets and no role or reseller field', function () {
        $this->actingAs($this->resellerA)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Sports')->assertSee('Movies')->assertDontSee('Kids')
            ->assertDontSee('name="role"', false)->assertDontSee('name="owner_id"', false);
    });

    it('makes the new user a subscriber of the reseller, whatever is posted', function () {
        $this->a2->delete(); // one slot free
        $this->actingAs($this->resellerA)->post(route('admin.users.store'), customerPayload([
            'role' => 'admin', 'owner_id' => $this->resellerB->id, 'max_customers' => 999, 'bouquets' => [$this->sports->id],
        ]))->assertSessionHasNoErrors();

        $user = User::where('username', 'newcust')->sole();
        expect($user->role)->toBe('user')->and($user->owner_id)->toBe($this->resellerA->id)
            ->and($user->max_customers)->toBeNull()
            ->and($user->bouquets->pluck('id')->all())->toBe([$this->sports->id]);
    });

    it('refuses bouquets the reseller does not have', function () {
        $this->resellerA->customers()->where('id', $this->a2->id)->delete(); // one slot free

        $this->actingAs($this->resellerA)->post(route('admin.users.store'), customerPayload(['bouquets' => [$this->kids->id]]))
            ->assertSessionHasErrors('bouquets.0');

        expect(User::where('username', 'newcust')->exists())->toBeFalse();
    });

    it('caps the simultaneous streams', function () {
        $this->resellerA->customers()->where('id', $this->a2->id)->delete();
        $this->actingAs($this->resellerA);

        $this->post(route('admin.users.store'), customerPayload(['max_connections' => 3]))->assertSessionHasErrors('max_connections');
        $this->post(route('admin.users.store'), customerPayload(['max_connections' => 2]))->assertSessionHasNoErrors();
    });

    it('stops at the limit of users', function () {
        $this->actingAs($this->resellerA)
            ->post(route('admin.users.store'), customerPayload())
            ->assertSessionHas('error');

        expect(User::where('username', 'newcust')->exists())->toBeFalse()
            ->and($this->resellerA->customers()->count())->toBe(2);
    });

    it('has no limit when none is set', function () {
        $this->resellerA->update(['max_customers' => null, 'max_customer_connections' => null]);
        $this->actingAs($this->resellerA);

        foreach (range(1, 4) as $i) {
            $this->post(route('admin.users.store'), customerPayload(['username' => "bulk$i", 'max_connections' => 9]))->assertSessionHasNoErrors();
        }

        expect($this->resellerA->customers()->count())->toBe(6);
    });
});

describe('editing users', function () {
    it('can not change the role or the reseller of a user', function () {
        $this->actingAs($this->resellerA)->put(route('admin.users.update', $this->a1), customerPayload([
            'username' => 'custA1', 'role' => 'admin', 'owner_id' => $this->resellerB->id, 'max_connections' => 1,
        ]))->assertSessionHasNoErrors();

        $fresh = $this->a1->fresh();
        expect($fresh->role)->toBe('user')->and($fresh->owner_id)->toBe($this->resellerA->id);
    });

    it('keeps the bouquets the admin gave a user that the reseller can not hand out', function () {
        $this->a1->bouquets()->attach([$this->kids->id, $this->sports->id]);

        $this->actingAs($this->resellerA)->put(route('admin.users.update', $this->a1), customerPayload([
            'username' => 'custA1', 'bouquets' => [$this->movies->id],
        ]))->assertSessionHasNoErrors();

        // Sports was unticked and goes; Kids is not the reseller's to take away
        expect($this->a1->fresh()->bouquets->pluck('id')->sort()->values()->all())->toBe(collect([$this->kids->id, $this->movies->id])->sort()->values()->all());
    });

    it('can not take a user\'s name, e-mail or anything else from someone else\'s record', function () {
        // the unique check still covers everybody, so a name can't be reused
        $this->actingAs($this->resellerA)->put(route('admin.users.update', $this->a1), customerPayload(['username' => 'custB1']))
            ->assertSessionHasErrors('username');
    });
});

describe('what the admin controls', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('creates a reseller with limits and their own bouquets', function () {
        $this->post(route('admin.users.store'), customerPayload([
            'username' => 'resC', 'role' => 'reseller', 'max_customers' => 5, 'max_customer_connections' => 3, 'bouquets' => [$this->kids->id],
        ]))->assertSessionHasNoErrors();

        $reseller = User::where('username', 'resC')->sole();
        expect($reseller->isReseller())->toBeTrue()
            ->and($reseller->max_customers)->toBe(5)->and($reseller->max_customer_connections)->toBe(3)
            ->and($reseller->owner_id)->toBeNull()
            ->and($reseller->bouquets->pluck('id')->all())->toBe([$this->kids->id]);
    });

    it('puts a subscriber under a reseller, and only a reseller', function () {
        $this->put(route('admin.users.update', $this->own), customerPayload(['username' => 'adminsCustomer', 'role' => 'user', 'owner_id' => $this->resellerB->id]))
            ->assertSessionHasNoErrors();
        expect($this->own->fresh()->owner_id)->toBe($this->resellerB->id);

        $this->put(route('admin.users.update', $this->own), customerPayload(['username' => 'adminsCustomer', 'role' => 'user', 'owner_id' => $this->a1->id]))
            ->assertSessionHasErrors('owner_id');
    });

    it('clears the owner and the limits when the role does not use them', function () {
        $this->put(route('admin.users.update', $this->a1), customerPayload(['username' => 'custA1', 'role' => 'user', 'owner_id' => $this->resellerB->id, 'max_customers' => 7]));
        $fresh = $this->a1->fresh();
        expect($fresh->owner_id)->toBe($this->resellerB->id)->and($fresh->max_customers)->toBeNull();

        $this->put(route('admin.users.update', $this->a1), customerPayload(['username' => 'custA1', 'role' => 'admin', 'owner_id' => $this->resellerB->id]));
        expect($this->a1->fresh()->owner_id)->toBeNull();
    });

    it('does not let an admin demote themselves, or a reseller with users stop being one, or delete them', function () {
        $this->put(route('admin.users.update', $this->admin), customerPayload(['username' => 'boss', 'role' => 'user']))->assertSessionHas('error');
        expect($this->admin->fresh()->isAdmin())->toBeTrue();

        $this->put(route('admin.users.update', $this->resellerA), customerPayload(['username' => 'resA', 'role' => 'user']))->assertSessionHas('error');
        expect($this->resellerA->fresh()->isReseller())->toBeTrue();

        $this->delete(route('admin.users.destroy', $this->resellerA))->assertSessionHas('error');
        expect(User::find($this->resellerA->id))->not->toBeNull();

        $this->resellerA->customers()->delete();
        $this->delete(route('admin.users.destroy', $this->resellerA))->assertRedirect(route('admin.users.index'));
        expect(User::find($this->resellerA->id))->toBeNull();
    });

    it('shows a reseller\'s users and limits on their page', function () {
        $this->get(route('admin.users.show', $this->resellerA))->assertSee('2 of 2')->assertSee('Most streams per user');
        $this->get(route('admin.users.show', $this->a1))->assertSee('resA');
    });

    it('keeps a reseller\'s users when the account is removed some other way', function () {
        DB::table('users')->where('id', $this->resellerA->id)->delete();

        expect($this->a1->fresh()->owner_id)->toBeNull();
    });
});

describe('what a reseller can see of the rest', function () {
    it('shows only the plays of its own users', function () {
        $channel = subscribedChannel($this->a1, ['name' => 'Arena']);
        $other = subscribedChannel($this->b1, ['name' => 'Hidden Channel']);
        StreamLog::insert([
            ['user_id' => $this->a1->id, 'channel_id' => $channel->id, 'started_at' => now()],
            ['user_id' => $this->b1->id, 'channel_id' => $other->id, 'started_at' => now()],
        ]);

        $this->actingAs($this->resellerA)->get(route('admin.logs.index'))->assertOk()->assertSee('Arena')->assertDontSee('Hidden Channel')->assertDontSee('custB1');
        $this->get(route('admin.logs.index', ['user' => $this->b1->id]))->assertDontSee('Hidden Channel');
        $this->get(route('admin.dashboard'))->assertSee('Arena')->assertDontSee('Hidden Channel');

        $this->actingAs($this->admin)->get(route('admin.logs.index'))->assertSee('Arena')->assertSee('Hidden Channel');
    });

    it('counts only its own users on the dashboard', function () {
        $this->a1->update(['expiration_date' => now()->addDays(3)]);
        $this->b1->update(['expiration_date' => now()->addDays(2)]);
        $this->a2->update(['expiration_date' => now()->subDay()]);
        $channel = subscribedChannel($this->a1);
        ActiveSession::create(['user_id' => $this->b1->id, 'channel_id' => $channel->id, 'ip' => '1.1.1.1', 'user_agent' => 'X', 'started_at' => now(), 'last_activity_at' => now()]);

        $page = $this->actingAs($this->resellerA)->get(route('admin.dashboard'))->assertOk();

        $page->assertSee('custA1')->assertDontSee('custB1')->assertSee('1 already expired');
        // nobody of its own is watching: the other reseller's viewer is not counted
        expect($page->getContent())->toMatch('/Watching now.*?stat-value">\s*0\s*</s');
    });

    it('sees the alerts about its own users, and can not mark them read', function () {
        $mine = StreamAlert::create(['type' => 'renewal_requested', 'user_id' => $this->a1->id, 'name' => 'custA1']);
        StreamAlert::create(['type' => 'renewal_requested', 'user_id' => $this->b1->id, 'name' => 'custB1']);
        StreamAlert::create(['type' => 'sync_failed', 'name' => 'Some source']);

        $this->actingAs($this->resellerA)->get(route('admin.alerts.index'))
            ->assertSee('custA1 asks to renew the subscription')
            ->assertDontSee('custB1')->assertDontSee('Some source');

        $this->post(route('admin.alerts.read'))->assertRedirect();
        expect($mine->fresh()->read_at)->toBeNull();

        // the bell shows no unread count for a reseller
        $this->get(route('admin.dashboard'))->assertDontSee('notif-count', false);

        $this->actingAs($this->admin)->post(route('admin.alerts.read'));
        expect($mine->fresh()->read_at)->not->toBeNull();
    });

    it('sees only its own sign-ins on the security page', function () {
        LoginEvent::create(['user_id' => $this->resellerA->id, 'username' => 'resA', 'event' => 'success']);
        LoginEvent::create(['user_id' => $this->admin->id, 'username' => 'boss', 'event' => 'success']);
        LoginEvent::create(['username' => 'ghost-guesser', 'event' => 'failed']);

        $this->actingAs($this->resellerA)->get(route('admin.security.index'))
            ->assertOk()->assertSee('resA')->assertDontSee('boss')->assertDontSee('ghost-guesser');

        $this->actingAs($this->admin)->get(route('admin.security.index'))->assertSee('resA')->assertSee('boss')->assertSee('ghost-guesser');
    });
});

describe('the reseller\'s own viewing', function () {
    it('plays what its bouquets give it, like any user', function () {
        $channel = Channel::create(['name' => 'Reseller Test', 'stream_url' => 'http://s.test/1.ts', 'is_active' => true]);
        $this->sports->channels()->attach($channel);

        $this->get(route('playlist', ['token' => $this->resellerA->playlist_token]))->assertOk();
        expect($this->get(route('playlist', ['token' => $this->resellerA->playlist_token]))->streamedContent())->toContain('Reseller Test');
    });

    it('has no page of its own under /me (that is for subscribers)', function () {
        $this->get(route('me', ['token' => $this->resellerA->playlist_token]))->assertNotFound();
    });
});

describe('the visibility rule', function () {
    it('gives admins everyone, resellers their users, anybody else nobody', function () {
        expect(User::visibleTo($this->admin)->count())->toBe(User::count())
            ->and(User::visibleTo($this->resellerA)->pluck('username')->sort()->values()->all())->toBe(['custA1', 'custA2'])
            ->and(User::visibleTo($this->a1)->count())->toBe(0)
            ->and(User::visibleTo(null)->count())->toBe(0);
    });
});

describe('hardening', function () {
    it('shows a reseller only the bouquets it holds on a user\'s page', function () {
        $this->a1->bouquets()->attach([$this->sports->id, $this->kids->id]);

        $this->actingAs($this->resellerA)->get(route('admin.users.show', $this->a1))->assertSee('Sports')->assertDontSee('Kids');
        $this->actingAs($this->admin)->get(route('admin.users.show', $this->a1))->assertSee('Sports')->assertSee('Kids');
    });

    it('ignores array-shaped filters instead of failing', function () {
        $this->actingAs($this->resellerA);

        $this->get('/admin/users?search[]=x&status[]=active&reseller[]=1')->assertOk();
        $this->get('/admin/logs?user[]=1')->assertOk();
    });

    it('still stops at the limit when the check runs inside the lock', function () {
        $this->actingAs($this->resellerA)->post(route('admin.users.store'), customerPayload())->assertSessionHas('error');

        expect($this->resellerA->customers()->count())->toBe(2);
    });
});
