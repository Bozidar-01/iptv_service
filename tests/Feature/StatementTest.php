<?php

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\ResellerStatementService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    $this->admin = User::factory()->admin()->create();
    $this->a = User::factory()->create(['username' => 'resA', 'role' => 'reseller', 'credits' => 12]);
    $this->b = User::factory()->create(['username' => 'resB', 'role' => 'reseller', 'credits' => 5]);
});

function tx(User $reseller, int $amount, string $reason, string $when, int $balance, ?User $customer = null): void
{
    CreditTransaction::create(['user_id' => $reseller->id, 'customer_id' => $customer?->id, 'amount' => $amount, 'balance_after' => $balance, 'reason' => $reason])->forceFill(['created_at' => Carbon::parse($when)])->save();
}

describe('the numbers', function () {
    it('adds up a month of credits and sales, with opening and closing balances', function () {
        $c = User::factory()->create(['username' => 'cust', 'owner_id' => $this->a->id]);
        tx($this->a, 10, CreditTransaction::GIVEN, '2026-09-20', 10);
        tx($this->a, 20, CreditTransaction::GIVEN, '2026-10-02', 30);
        tx($this->a, -3, CreditTransaction::NEW_SUBSCRIPTION, '2026-10-05', 27, $c);
        tx($this->a, -12, CreditTransaction::EXTENDED, '2026-10-10', 15, $c);
        tx($this->a, -1, CreditTransaction::EXTENDED, '2026-10-11', 14, $c);
        tx($this->a, -2, CreditTransaction::TAKEN, '2026-10-12', 12);
        tx($this->a, -5, CreditTransaction::EXTENDED, '2026-11-02', 7, $c);   // next month

        $s = app(ResellerStatementService::class)->build($this->a, Carbon::parse('2026-10-01'));

        expect($s['credits'])->toBe(['opening' => 10, 'given' => 20, 'taken_back' => 2, 'used_new' => 3, 'used_renewals' => 13, 'closing' => 12])
            ->and($s['sales'])->toBe(['new_subscriptions' => 1, 'renewals' => 2])
            ->and($s['lines'])->toHaveCount(5)->and($s['lines'][0])->toMatchArray(['type' => 'Credits given', 'credits' => 20, 'balance' => 30]);
    });

    it('counts the reseller\'s users: added, ended, active', function () {
        $mine = fn (array $a) => User::factory()->create(['owner_id' => $this->a->id] + $a);
        $mine(['created_at' => '2026-10-03', 'expiration_date' => '2026-12-31']);
        $mine(['created_at' => '2026-05-03', 'expiration_date' => '2026-10-08']);
        $mine(['created_at' => '2026-05-03', 'expiration_date' => '2026-11-08']);
        $mine(['created_at' => '2026-05-03', 'expiration_date' => '2027-01-01', 'status' => 'disabled']);
        User::factory()->create(['owner_id' => $this->b->id, 'created_at' => '2026-10-04']);

        $s = app(ResellerStatementService::class)->build($this->a, Carbon::parse('2026-10-01'));

        expect($s['users'])->toBe(['added' => 1, 'ended' => 1, 'active_now' => 2, 'total' => 4]);
    });

    it('has no figures for a month without history and says unlimited for a reseller without credits', function () {
        $none = User::factory()->create(['role' => 'reseller', 'credits' => null]);

        $s = app(ResellerStatementService::class)->build($none, Carbon::parse('2026-03-01'));

        expect($s['reseller']['unlimited'])->toBeTrue()->and($s['credits']['opening'])->toBeNull()->and($s['credits']['closing'])->toBeNull()->and($s['lines'])->toBe([]);
    });

    it('lists the months since the first transaction', function () {
        tx($this->a, 5, CreditTransaction::GIVEN, '2026-08-15', 5);

        expect(app(ResellerStatementService::class)->months($this->a))->toBe(['2026-10', '2026-09', '2026-08'])
            ->and(app(ResellerStatementService::class)->months($this->b))->toBe(['2026-10']);
    });
});

describe('the page', function () {
    it('shows a reseller their own statement, whatever they ask for', function () {
        tx($this->a, 20, CreditTransaction::GIVEN, '2026-10-02', 20);
        tx($this->b, 99, CreditTransaction::GIVEN, '2026-10-02', 99);

        $this->actingAs($this->a)->get(route('admin.statements.index', ['reseller' => $this->b->id]))->assertOk()
            ->assertSee('resA')->assertSee('Credits given')->assertDontSee('99');
        $this->actingAs($this->a)->get(route('admin.statements.csv', ['reseller' => $this->b->id, 'month' => '2026-10']))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=statement-resA-2026-10.csv');
    });

    it('lets an admin pick the reseller and the month', function () {
        tx($this->b, 7, CreditTransaction::GIVEN, '2026-09-02', 7);

        $this->actingAs($this->admin)->get(route('admin.statements.index', ['reseller' => $this->b->id, 'month' => '2026-09']))->assertOk()->assertSee('resB')->assertSee('September 2026')->assertSee('Credits given');
        // an unknown reseller or a broken month falls back
        $this->actingAs($this->admin)->get(route('admin.statements.index', ['reseller' => 99999, 'month' => 'nonsense']))->assertOk()->assertSee('resA')->assertSee('October 2026');
        // the future is not offered
        $this->actingAs($this->admin)->get(route('admin.statements.index', ['month' => '2030-01']))->assertSee('October 2026');
    });

    it('downloads a CSV with the figures and the history, safe for spreadsheets', function () {
        $c = User::factory()->create(['username' => '=evil', 'owner_id' => $this->a->id]);
        tx($this->a, -3, CreditTransaction::NEW_SUBSCRIPTION, '2026-10-05', 9, $c);

        $csv = $this->actingAs($this->admin)->get(route('admin.statements.csv', ['reseller' => $this->a->id, 'month' => '2026-10']))->streamedContent();

        expect($csv)->toContain('resA,2026-10')->toContain('"Used for new subscriptions",3')->toContain("'=evil")->toContain('Closing balance');
    });

    it('is for staff only, with a menu entry for both, and works with no reseller', function () {
        // A tab of the Money section
        $this->actingAs($this->admin)->get(route('admin.payments.index'))->assertSee(route('admin.statements.index'), false);
        $this->actingAs($this->a)->get(route('admin.payments.index'))->assertSee(route('admin.statements.index'), false);

        User::where('role', 'reseller')->delete();
        $this->actingAs($this->admin)->get(route('admin.statements.index'))->assertOk()->assertSee('No resellers yet');
        $this->actingAs($this->admin)->get(route('admin.statements.csv'))->assertNotFound();

        auth()->logout();
        $this->get(route('admin.statements.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.statements.index'))->assertRedirect(route('login'));
    });
});

it('shows the month\'s credits as a ledger and keeps the page markup balanced', function () {
    tx($this->a, 20, CreditTransaction::GIVEN, '2026-10-02', 20);
    tx($this->a, -3, CreditTransaction::NEW_SUBSCRIPTION, '2026-10-05', 17);

    $html = $this->actingAs($this->admin)->get(route('admin.statements.index', ['reseller' => $this->a->id]))->assertOk()
        ->assertSeeInOrder(['Opening balance', 'Credits given', '+20', 'Used for new subscriptions', '−3', 'Closing balance', '17'])->getContent();

    expect(substr_count($html, '<div'))->toBe(substr_count($html, '</div>'));

    $unlimited = User::factory()->create(['role' => 'reseller', 'credits' => null]);
    $this->actingAs($this->admin)->get(route('admin.statements.index', ['reseller' => $unlimited->id]))->assertSee('Not limited by credits');
});
