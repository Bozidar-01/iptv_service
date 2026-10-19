<?php

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\SalesReportService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');

    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->a = User::factory()->create(['username' => 'resA', 'role' => 'reseller', 'credits' => 20]);
    $this->b = User::factory()->create(['username' => 'resB', 'role' => 'reseller', 'credits' => null]);
});

function ledger(User $reseller, int $amount, string $reason, string $when): void
{
    $tx = CreditTransaction::create(['user_id' => $reseller->id, 'amount' => $amount, 'balance_after' => 0, 'reason' => $reason]);
    $tx->forceFill(['created_at' => Carbon::parse($when)])->save();
}

function rowFor(array $report, ?string $name): array
{
    return collect($report['rows'])->firstWhere('name', $name);
}

describe('the numbers', function () {
    it('counts months sold and credits given per reseller in the period', function () {
        ledger($this->a, 30, CreditTransaction::GIVEN, '2026-10-01');
        ledger($this->a, -3, CreditTransaction::NEW_SUBSCRIPTION, '2026-10-05');
        ledger($this->a, -2, CreditTransaction::EXTENDED, '2026-10-10');
        ledger($this->a, -4, CreditTransaction::EXTENDED, '2026-08-01');   // outside 30 days, inside 90
        ledger($this->a, -1, CreditTransaction::TAKEN, '2026-10-12');      // taken back is not a sale

        $thirty = app(SalesReportService::class)->report(30);
        $ninety = app(SalesReportService::class)->report(90);

        expect(rowFor($thirty, 'resA'))->used->toBe(5)->given->toBe(30)
            ->and(rowFor($ninety, 'resA'))->used->toBe(9)
            ->and($thirty['totals']['used'])->toBe(5)->and($thirty['totals']['given'])->toBe(30);
    });

    it('shows the months sold per calendar month, empty months included', function () {
        ledger($this->a, -3, CreditTransaction::NEW_SUBSCRIPTION, '2026-10-05');
        ledger($this->b, -2, CreditTransaction::EXTENDED, '2026-10-06');
        ledger($this->a, -4, CreditTransaction::EXTENDED, '2026-08-20');
        ledger($this->a, -9, CreditTransaction::EXTENDED, '2025-01-01');   // before the 12 months

        $months = app(SalesReportService::class)->report(30)['months'];

        expect($months)->toHaveCount(12)->and(array_key_first($months))->toBe('2025-11')->and(array_key_last($months))->toBe('2026-10')
            ->and($months['2026-10'])->toBe(5)->and($months['2026-08'])->toBe(4)->and($months['2026-09'])->toBe(0)->and(array_sum($months))->toBe(9);
    });

    it('estimates how many days the credits last at the pace of the last 30 days', function () {
        ledger($this->a, -30, CreditTransaction::EXTENDED, '2026-10-05');   // 1 credit a day

        expect(rowFor(app(SalesReportService::class)->report(30), 'resA')['days_left'])->toBe(20)
            ->and(rowFor(app(SalesReportService::class)->report(30), 'resB')['days_left'])->toBeNull();

        $this->a->forceFill(['credits' => 5])->save();
        expect(rowFor(app(SalesReportService::class)->report(30), 'resA')['days_left'])->toBe(5);
    });

    it('counts each reseller\'s users, new ones, those expiring and those that just expired', function () {
        $mine = fn (array $attrs) => User::factory()->create(['owner_id' => $this->a->id] + $attrs);
        $mine(['expiration_date' => '2026-12-31', 'created_at' => '2026-10-10']);                    // active, new
        $mine(['expiration_date' => '2026-10-25', 'created_at' => '2026-01-01']);                    // expiring in 10 days
        $mine(['expiration_date' => '2026-10-05', 'created_at' => '2026-01-01']);                    // expired 10 days ago
        $mine(['expiration_date' => '2026-06-01', 'created_at' => '2026-01-01']);                    // expired long ago
        $mine(['expiration_date' => '2026-12-31', 'created_at' => '2026-01-01', 'status' => 'disabled']);
        User::factory()->create(['owner_id' => null, 'expiration_date' => '2026-10-20', 'created_at' => '2026-10-14']);

        $report = app(SalesReportService::class)->report(30);

        expect(rowFor($report, 'resA'))->total->toBe(5)->active->toBe(2)->new->toBe(1)->expiring->toBe(1)->expired->toBe(1)
            ->and(rowFor($report, null))->total->toBe(1)->new->toBe(1)->expiring->toBe(1)
            ->and($report['totals']['active'])->toBe(3);
    });

    it('treats an unknown period as 30 days', function () {
        expect(app(SalesReportService::class)->report(7)['days'])->toBe(30)->and(app(SalesReportService::class)->report(365)['days'])->toBe(365);
    });
});

describe('the page', function () {
    it('shows the report to an admin, with a warning for low credits', function () {
        ledger($this->a, -30, CreditTransaction::EXTENDED, '2026-10-05');
        $this->a->forceFill(['credits' => 10])->save();

        $this->actingAs($this->admin)->get(route('admin.sales.index'))->assertOk()
            ->assertSee('Sales')->assertSee('resA')->assertSee('resB')->assertSee('about 10 days left')->assertSee('Unlimited')
            ->assertSee('Months sold per month');
        $this->actingAs($this->admin)->get(route('admin.sales.index', ['days' => 90]))->assertSee('Last 90 days');
    });

    it('is for admins only, and in their menu', function () {
        // A tab of the Money section, for admins only
        $this->actingAs($this->admin)->get(route('admin.payments.index'))->assertSee(route('admin.sales.index'), false);
        $this->actingAs($this->a)->get(route('admin.sales.index'))->assertForbidden();
        $this->actingAs($this->a)->get(route('admin.payments.index'))->assertDontSee(route('admin.sales.index'), false);

        auth()->logout();
        $this->get(route('admin.sales.index'))->assertRedirect(route('login'));
    });

    it('works with nothing to report', function () {
        User::where('role', 'reseller')->delete();

        $this->actingAs($this->admin)->get(route('admin.sales.index'))->assertOk();
    });
});
