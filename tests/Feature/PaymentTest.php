<?php

use App\Models\Bouquet;
use App\Models\CreditTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Services\CreditService;
use App\Services\SaleService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->bouquet = Bouquet::create(['name' => 'Sports']);
    $this->reseller = User::factory()->create([
        'username' => 'resA', 'role' => 'reseller', 'credits' => 10, 'expiration_date' => null,
        'max_customers' => null, 'max_customer_connections' => null, 'price_per_month' => 8,
    ]);
    $this->reseller->bouquets()->attach($this->bouquet);
    app(SaleService::class)->saveSettings(['currency' => 'MKD', 'price_per_month' => 5, 'credit_price' => 3]);
});

function payCustomer(array $over = []): array
{
    return array_merge([
        'username' => 'fresh', 'password' => 'secret1', 'status' => 'active', 'max_connections' => 1,
        'expiration_date' => now()->addMonths(2)->toDateString(), 'bouquets' => [test()->bouquet->id],
    ], $over);
}

describe('sales', function () {
    it('records a new subscription at the reseller\'s price, and a renewal', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.store'), payCustomer())->assertRedirect();
        $user = User::where('username', 'fresh')->first();

        $this->post(route('admin.users.renew', $user), ['months' => 3])->assertRedirect();

        expect(Sale::orderBy('id')->get(['owner_id', 'customer_id', 'kind', 'months', 'amount'])->toArray())->toBe([
            ['owner_id' => $this->reseller->id, 'customer_id' => $user->id, 'kind' => 'new', 'months' => 2, 'amount' => '16.00'],
            ['owner_id' => $this->reseller->id, 'customer_id' => $user->id, 'kind' => 'renewal', 'months' => 3, 'amount' => '24.00'],
        ]);
    });

    it('records the admins\' own users at their price, and a later end date as a renewal', function () {
        $this->actingAs($this->admin)->post(route('admin.users.store'), payCustomer(['role' => 'user']))->assertRedirect();
        $user = User::where('username', 'fresh')->first();

        $this->put(route('admin.users.update', $user), payCustomer(['role' => 'user', 'password' => '', 'expiration_date' => now()->addMonths(3)->toDateString()]));
        // Shorter costs nothing and is not a sale
        $this->put(route('admin.users.update', $user), payCustomer(['role' => 'user', 'password' => '', 'expiration_date' => now()->addMonth()->toDateString()]));

        expect(Sale::orderBy('id')->get(['owner_id', 'kind', 'months', 'amount'])->toArray())->toBe([
            ['owner_id' => null, 'kind' => 'new', 'months' => 2, 'amount' => '10.00'],
            ['owner_id' => null, 'kind' => 'renewal', 'months' => 1, 'amount' => '5.00'],
        ]);
    });

    it('records bulk extensions, leaves trials and users without an end date out, and works without a price', function () {
        app(SaleService::class)->saveSettings(['price_per_month' => null]);
        $a = User::factory()->create(['expiration_date' => now()->addDays(5)]);
        $trial = User::factory()->create(['expiration_date' => now()->addDay(), 'is_trial' => true]);

        $this->actingAs($this->admin)->post(route('admin.users.bulk'), ['action' => 'extend', 'months' => 1, 'scope' => 'selected', 'ids' => [$a->id]])->assertRedirect();
        app(SaleService::class)->record($trial, now(), now()->addMonth(), Sale::NEW);
        app(SaleService::class)->record(User::factory()->create(), null, null, Sale::NEW);

        expect(Sale::count())->toBe(1)->and(Sale::first())->customer_id->toBe($a->id)->amount->toBeNull();
    });
});

describe('the page', function () {
    it('lists unpaid sales, and the seller marks them paid and not paid', function () {
        $user = User::factory()->create(['owner_id' => $this->reseller->id, 'username' => 'payer']);
        $sale = app(SaleService::class)->record($user, null, now()->addMonth(), Sale::NEW);
        $other = app(SaleService::class)->record(User::factory()->create(['username' => 'adminsown']), null, now()->addMonth(), Sale::NEW);

        $this->actingAs($this->reseller)->get(route('admin.payments.index'))->assertOk()
            ->assertSee('payer')->assertDontSee('adminsown')->assertSee('8.00 MKD');

        $this->post(route('admin.payments.paid', $sale), ['paid' => 1])->assertSessionHas('success', 'payer marked as paid.');
        expect($sale->fresh()->paid_at)->not->toBeNull();
        $this->post(route('admin.payments.paid', $sale), ['paid' => 0]);
        expect($sale->fresh()->paid_at)->toBeNull();

        // Not their sale
        $this->post(route('admin.payments.paid', $other), ['paid' => 1])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.payments.index'))->assertSee('payer')->assertSee('adminsown');
    });

    it('charges resellers for credits given at their price and records what they pay', function () {
        $credits = app(CreditService::class);
        $credits->adjust($this->admin, $this->reseller, 10);
        $this->reseller->forceFill(['credit_price' => 2.5])->save();
        $credits->adjust($this->admin, $this->reseller->fresh(), 4);
        $credits->adjust($this->admin, $this->reseller->fresh(), -2);

        expect(CreditTransaction::where('reason', CreditTransaction::GIVEN)->pluck('price')->all())->toBe(['3.00', '2.50']);

        $this->actingAs($this->admin)->post(route('admin.payments.reseller', $this->reseller), ['amount' => 20, 'note' => 'cash'])
            ->assertSessionHas('success', 'Payment from resA recorded.');

        // 10 × 3 + 4 × 2.5 − 2 × 2.5 = 35, paid 20
        expect(app(SaleService::class)->balances([$this->reseller->id])[$this->reseller->id])->toMatchArray(['charged' => 35.0, 'paid' => 20.0, 'owes' => 15.0]);
        $this->get(route('admin.payments.index'))->assertSee('15.00 MKD')->assertSee('cash');
        $this->actingAs($this->reseller)->get(route('admin.payments.index'))->assertSee('You owe for credits')->assertSee('15.00 MKD');
        $this->post(route('admin.payments.reseller', $this->reseller), ['amount' => 5])->assertForbidden();
    });

    it('saves prices: the admins\' and per reseller, and a reseller\'s own', function () {
        $this->actingAs($this->admin)->post(route('admin.payments.prices'), ['currency' => 'eur', 'price_per_month' => '6', 'credit_price' => '', 'credit_prices' => [$this->reseller->id => '4']])
            ->assertSessionHas('success');
        expect(app(SaleService::class)->settings())->toBe(['currency' => 'EUR', 'price_per_month' => 6.0, 'credit_price' => null])
            ->and($this->reseller->fresh()->credit_price)->toBe('4.00');

        $this->actingAs($this->reseller)->post(route('admin.payments.prices'), ['price_per_month' => '9.5', 'currency' => 'XXX'])->assertSessionHas('success');
        expect($this->reseller->fresh()->price_per_month)->toBe('9.50')->and(app(SaleService::class)->settings()['currency'])->toBe('EUR');
    });

    it('shows money on the statement and what resellers owe on the Sales page', function () {
        app(CreditService::class)->adjust($this->admin, $this->reseller, 2);
        $user = User::factory()->create(['owner_id' => $this->reseller->id]);
        app(SaleService::class)->setPaid(app(SaleService::class)->record($user, null, now()->addMonth(), Sale::NEW), true);

        $this->actingAs($this->admin)->get(route('admin.statements.index', ['reseller' => $this->reseller->id]))->assertOk()
            ->assertSee('Received from users this month')->assertSee('8.00 MKD')->assertSee('6.00 MKD');
        $this->get(route('admin.sales.index'))->assertOk()->assertSee('Owes')->assertSee('6.00 MKD');
    });
});
