<?php

use App\Models\AuditLog;
use App\Models\Bouquet;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->sports = Bouquet::create(['name' => 'Sports']);
    $this->movies = Bouquet::create(['name' => 'Movies']);
    $this->reseller = User::factory()->create(['username' => 'resA', 'role' => 'reseller', 'credits' => 10]);
    $this->reseller->bouquets()->attach($this->sports);
    $this->other = User::factory()->create(['username' => 'resB', 'role' => 'reseller', 'credits' => 10]);

    $this->u1 = User::factory()->create(['username' => 'u1', 'owner_id' => $this->reseller->id, 'expiration_date' => Carbon::parse('2026-10-20'), 'status' => 'active']);
    $this->u2 = User::factory()->create(['username' => 'u2', 'owner_id' => $this->reseller->id, 'expiration_date' => Carbon::parse('2026-08-01'), 'status' => 'active']);
    $this->u3 = User::factory()->create(['username' => 'u3', 'owner_id' => $this->other->id, 'expiration_date' => Carbon::parse('2026-10-20'), 'status' => 'active']);
});

function bulk(User $actor, string $action, array $ids, array $extra = [])
{
    return test()->actingAs($actor)->post(route('admin.users.bulk'), array_merge(['action' => $action, 'scope' => 'selected', 'ids' => $ids], $extra));
}

describe('enable, disable, delete', function () {
    it('disables and enables the selected users only', function () {
        bulk($this->admin, 'disable', [$this->u1->id, $this->u3->id])->assertSessionHas('success', '2 users disabled.');

        expect($this->u1->fresh()->status)->toBe('disabled')->and($this->u3->fresh()->status)->toBe('disabled')->and($this->u2->fresh()->status)->toBe('active');

        bulk($this->admin, 'enable', [$this->u1->id, $this->u2->id])->assertSessionHas('success', '1 user enabled.');
        expect($this->u1->fresh()->status)->toBe('active');
    });

    it('deletes users, never admins, resellers or the person asking', function () {
        $admin2 = User::factory()->admin()->create();

        bulk($this->admin, 'delete', [$this->u1->id, $this->reseller->id, $admin2->id, $this->admin->id])->assertSessionHas('success', '1 user deleted.');

        expect(User::find($this->u1->id))->toBeNull()->and(User::find($this->reseller->id))->not->toBeNull()->and(User::find($admin2->id))->not->toBeNull()->and(User::find($this->admin->id))->not->toBeNull();
    });
});

describe('bouquets', function () {
    it('adds a bouquet to users who lack it, once, and removes it again', function () {
        $this->u1->bouquets()->attach($this->sports);

        bulk($this->admin, 'add_bouquet', [$this->u1->id, $this->u2->id], ['bouquet_id' => $this->sports->id])->assertSessionHas('success', '1 user got the bouquet.');
        expect($this->u2->bouquets()->pluck('bouquets.id')->all())->toBe([$this->sports->id])->and($this->u1->bouquets()->count())->toBe(1);

        bulk($this->admin, 'remove_bouquet', [$this->u1->id, $this->u2->id, $this->u3->id], ['bouquet_id' => $this->sports->id])->assertSessionHas('success', '2 users lost the bouquet.');
        expect($this->u1->bouquets()->count())->toBe(0);
    });

    it('lets a reseller use only the bouquets they hold', function () {
        bulk($this->reseller, 'add_bouquet', [$this->u1->id], ['bouquet_id' => $this->movies->id])->assertSessionHasErrors('bouquet_id');
        bulk($this->reseller, 'add_bouquet', [$this->u1->id], ['bouquet_id' => $this->sports->id])->assertSessionHas('success');

        expect($this->u1->bouquets()->pluck('bouquets.id')->all())->toBe([$this->sports->id]);
    });
});

describe('renewing', function () {
    it('moves every end date later, from the end date or from now, for free as an admin', function () {
        bulk($this->admin, 'extend', [$this->u1->id, $this->u2->id], ['months' => 3])->assertSessionHas('success', '2 users renewed.');

        expect($this->u1->fresh()->expiration_date->toDateString())->toBe('2027-01-20')
            ->and($this->u2->fresh()->expiration_date->toDateString())->toBe('2027-01-09')
            ->and(CreditTransaction::count())->toBe(0);
    });

    it('charges a reseller per user, all or nothing', function () {
        bulk($this->reseller, 'extend', [$this->u1->id, $this->u2->id], ['months' => 3])->assertSessionHas('success', fn ($m) => str_contains($m, '2 users renewed.') && str_contains($m, '6 credits used, 4 left.'));

        expect($this->reseller->fresh()->credits)->toBe(4)->and(CreditTransaction::where('reason', CreditTransaction::EXTENDED)->count())->toBe(2);

        // 4 credits left, 6 needed: nothing changes
        $before = $this->u1->fresh()->expiration_date->toDateString();
        bulk($this->reseller, 'extend', [$this->u1->id, $this->u2->id], ['months' => 3])->assertSessionHas('error', 'Not enough credits: this needs 6, you have 4.');
        expect($this->reseller->fresh()->credits)->toBe(4)->and($this->u1->fresh()->expiration_date->toDateString())->toBe($before);
    });

    it('does not charge a reseller without a credit balance, and skips users who never expire', function () {
        $this->reseller->forceFill(['credits' => null])->save();
        $this->u2->forceFill(['expiration_date' => null])->save();

        bulk($this->reseller, 'extend', [$this->u1->id, $this->u2->id], ['months' => 12])->assertSessionHas('success', '1 user renewed.');

        expect($this->u1->fresh()->expiration_date->toDateString())->toBe('2027-10-20')->and($this->u2->fresh()->expiration_date)->toBeNull();
    });

    it('turns trials into normal users and needs a length', function () {
        $this->u1->forceFill(['is_trial' => true])->save();

        bulk($this->admin, 'extend', [$this->u1->id])->assertSessionHasErrors('months');
        bulk($this->admin, 'extend', [$this->u1->id], ['months' => 2])->assertSessionHasErrors('months');
        bulk($this->admin, 'extend', [$this->u1->id], ['months' => 1]);

        expect($this->u1->fresh()->is_trial)->toBeFalse();
    });
});

describe('scope and access', function () {
    it('keeps a reseller to their own users', function () {
        bulk($this->reseller, 'disable', [$this->u1->id, $this->u3->id])->assertSessionHas('success', '1 user disabled.');

        expect($this->u3->fresh()->status)->toBe('active');
    });

    it('applies to everything matching the list filters', function () {
        $this->u1->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($this->admin)->post(route('admin.users.bulk'), ['action' => 'enable', 'scope' => 'filtered', 'filters' => ['status' => 'disabled']])
            ->assertSessionHas('success', '1 user enabled.');
        expect($this->u1->fresh()->status)->toBe('active');

        $this->actingAs($this->reseller)->post(route('admin.users.bulk'), ['action' => 'disable', 'scope' => 'filtered', 'filters' => ['search' => 'u']])
            ->assertSessionHas('success', '2 users disabled.');
        expect($this->u3->fresh()->status)->toBe('active');
    });

    it('needs a selection and a known action, and is for staff only', function () {
        $this->actingAs($this->admin)->post(route('admin.users.bulk'), ['action' => 'disable', 'scope' => 'selected'])->assertSessionHasErrors('ids');
        $this->actingAs($this->admin)->post(route('admin.users.bulk'), ['action' => 'nonsense', 'scope' => 'selected', 'ids' => [1]])->assertSessionHasErrors('action');

        auth()->logout();
        $this->post(route('admin.users.bulk'), ['action' => 'disable', 'scope' => 'selected', 'ids' => [$this->u1->id]])->assertRedirect(route('login'));
        $this->actingAs($this->u1)->post(route('admin.users.bulk'), ['action' => 'disable', 'scope' => 'selected', 'ids' => [$this->u1->id]])->assertRedirect(route('login'));
        expect($this->u1->fresh()->status)->toBe('active');
    });

    it('is written to the audit log, and nothing when nothing changed', function () {
        bulk($this->reseller, 'extend', [$this->u1->id], ['months' => 1]);

        $entry = AuditLog::latest('id')->first();
        expect($entry->action)->toBe(AuditLog::USERS_BULK)->and($entry->actor_name)->toBe('resA')
            ->and($entry->summary())->toBe('renewed: 1 user · 1 credit used');

        $count = AuditLog::count();
        bulk($this->admin, 'enable', [$this->u1->id]);
        expect(AuditLog::count())->toBe($count);
    });

    it('shows the checkboxes and the bar on the users page, for subscribers only', function () {
        $page = $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertSee('id="bulk-form"', false);

        expect(substr_count($page->getContent(), 'name="ids[]"'))->toBe(3);
        $this->actingAs($this->reseller)->get(route('admin.users.index'))->assertSee('Sports')->assertSee('id="bulk-action"', false);
    });
});
