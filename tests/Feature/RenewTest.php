<?php

use App\Models\AuditLog;
use App\Models\CreditTransaction;
use App\Models\StreamAlert;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->reseller = User::factory()->create(['username' => 'resA', 'role' => 'reseller', 'credits' => 10]);
    $this->customer = User::factory()->create(['username' => 'cust1', 'owner_id' => $this->reseller->id, 'expiration_date' => Carbon::parse('2026-10-20 00:00:00')]);
});

function renew(User $actor, User $user, int $months = 1)
{
    return test()->actingAs($actor)->post(route('admin.users.renew', $user), ['months' => $months]);
}

describe('as an admin', function () {
    it('adds months to the current end date, for free', function () {
        renew($this->admin, $this->customer, 3)->assertRedirect()->assertSessionHas('success', 'Renewed for 3 months. Now valid until 20 January 2027.');

        expect($this->customer->fresh()->expiration_date->toDateString())->toBe('2027-01-20')
            ->and($this->reseller->fresh()->credits)->toBe(10)
            ->and(CreditTransaction::count())->toBe(0);
    });

    it('counts from now when the subscription already ended', function () {
        $this->customer->forceFill(['expiration_date' => Carbon::parse('2026-08-01')])->save();

        renew($this->admin, $this->customer, 1);

        expect($this->customer->fresh()->expiration_date->toDateTimeString())->toBe('2026-11-09 12:00:00');
    });

    it('keeps month ends in range', function () {
        $this->customer->forceFill(['expiration_date' => Carbon::parse('2027-01-31')])->save();

        renew($this->admin, $this->customer, 1);

        expect($this->customer->fresh()->expiration_date->toDateString())->toBe('2027-02-28');
    });

    it('turns a trial into a normal user and clears the alerts about the subscription', function () {
        $this->customer->forceFill(['is_trial' => true])->save();
        $asked = StreamAlert::create(['type' => StreamAlert::TYPE_RENEWAL_REQUESTED, 'user_id' => $this->customer->id, 'name' => 'cust1']);
        $other = StreamAlert::create(['type' => StreamAlert::TYPE_DOWN, 'name' => 'Some channel']);

        renew($this->admin, $this->customer);

        expect($this->customer->fresh()->is_trial)->toBeFalse()
            ->and($asked->fresh()->read_at)->not->toBeNull()
            ->and($other->fresh()->read_at)->toBeNull();
    });

    it('is written to the audit log', function () {
        renew($this->admin, $this->customer, 6);

        $entry = AuditLog::latest('id')->first();
        expect($entry->action)->toBe(AuditLog::USER_RENEWED)->and($entry->actor_name)->toBe('boss')->and($entry->subject_name)->toBe('cust1')
            ->and($entry->details['months'])->toBe(6)
            ->and($entry->details['changes']['expires'][1])->toStartWith('2027-04-20')
            ->and($entry->summary())->toContain('6 months')->toContain('Expires: 2026-10-20 00:00 → 2027-04-20 00:00');
    });

    it('only accepts the offered lengths, subscribers with an end date', function () {
        renew($this->admin, $this->customer, 2)->assertSessionHasErrors('months');
        renew($this->admin, $this->reseller)->assertNotFound();

        $this->customer->forceFill(['expiration_date' => null])->save();
        renew($this->admin, $this->customer)->assertSessionHas('error', 'This user never expires, so there is nothing to renew.');
        expect($this->customer->fresh()->expiration_date)->toBeNull();
    });
});

describe('as a reseller', function () {
    it('pays one credit per month', function () {
        renew($this->reseller, $this->customer, 3)->assertSessionHas('success', 'Renewed for 3 months. Now valid until 20 January 2027. 3 credits used, 7 left.');

        expect($this->reseller->fresh()->credits)->toBe(7)
            ->and($this->customer->fresh()->expiration_date->toDateString())->toBe('2027-01-20');

        $tx = CreditTransaction::latest('id')->first();
        expect($tx->amount)->toBe(-3)->and($tx->reason)->toBe(CreditTransaction::EXTENDED)->and($tx->customer_id)->toBe($this->customer->id);
        expect(AuditLog::latest('id')->first()->details['credits_spent'])->toBe(3);
    });

    it('pays the same whatever the month ends are', function (string $from, int $months) {
        $this->reseller->forceFill(['credits' => 20])->save();
        $this->customer->forceFill(['expiration_date' => Carbon::parse($from)])->save();

        renew($this->reseller, $this->customer, $months);

        expect($this->reseller->fresh()->credits)->toBe(20 - $months);
    })->with([['2026-01-31', 1], ['2027-01-31', 1], ['2026-12-31', 3], ['2026-10-31', 3], ['2026-10-09', 12]]);

    it('is refused when the credits are not enough, and nothing changes', function () {
        $this->reseller->forceFill(['credits' => 2])->save();

        renew($this->reseller, $this->customer, 3)->assertSessionHas('error', 'Not enough credits: this needs 3, you have 2.');

        expect($this->reseller->fresh()->credits)->toBe(2)
            ->and($this->customer->fresh()->expiration_date->toDateString())->toBe('2026-10-20')
            ->and(AuditLog::where('action', AuditLog::USER_RENEWED)->count())->toBe(0);
    });

    it('does not pay without a credit balance', function () {
        $this->reseller->forceFill(['credits' => null])->save();

        renew($this->reseller, $this->customer, 12);

        expect($this->reseller->fresh()->credits)->toBeNull()->and($this->customer->fresh()->expiration_date->toDateString())->toBe('2027-10-20');
    });

    it('can not renew someone else\'s user', function () {
        $other = User::factory()->create(['role' => 'reseller', 'credits' => 10]);

        renew($other, $this->customer)->assertNotFound();

        expect($this->customer->fresh()->expiration_date->toDateString())->toBe('2026-10-20')->and($other->fresh()->credits)->toBe(10);
    });
});

describe('the buttons', function () {
    it('are on the user page with the cost for a reseller', function () {
        $this->actingAs($this->admin)->get(route('admin.users.show', $this->customer))->assertSee(route('admin.users.renew', $this->customer), false)->assertSee('12 months')->assertDontSee('12 credits');
        $this->actingAs($this->reseller)->get(route('admin.users.show', $this->customer))->assertSee('12 months · 12 credits');

        $this->customer->forceFill(['expiration_date' => null])->save();
        $this->actingAs($this->admin)->get(route('admin.users.show', $this->customer))->assertDontSee(route('admin.users.renew', $this->customer), false);
    });

    it('are on renewal alerts', function () {
        StreamAlert::create(['type' => StreamAlert::TYPE_RENEWAL_REQUESTED, 'user_id' => $this->customer->id, 'name' => 'cust1']);
        StreamAlert::create(['type' => StreamAlert::TYPE_DOWN, 'name' => 'Some channel']);

        $page = $this->actingAs($this->admin)->get(route('admin.alerts.index'))->assertSee('Renew 1 month');
        expect(substr_count($page->getContent(), 'Renew 1 month'))->toBe(1);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertDontSee('Renew 1 month');
    });
});
