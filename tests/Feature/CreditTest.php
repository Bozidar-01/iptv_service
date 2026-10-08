<?php

use App\Models\Bouquet;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\CreditService;
use App\Support\Credits;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->bouquet = Bouquet::create(['name' => 'Sports']);
    $this->reseller = User::factory()->create([
        'username' => 'resA', 'role' => 'reseller', 'credits' => 5, 'expiration_date' => null,
        'max_customers' => null, 'max_customer_connections' => null,
    ]);
    $this->reseller->bouquets()->attach($this->bouquet);
    $this->customer = User::factory()->create(['username' => 'cust1', 'owner_id' => $this->reseller->id, 'expiration_date' => now()->addDays(10)]);
});

function newCustomer(array $over = []): array
{
    return array_merge([
        'username' => 'fresh', 'password' => 'secret1', 'status' => 'active', 'max_connections' => 1,
        'expiration_date' => now()->addMonth()->toDateString(), 'bouquets' => [test()->bouquet->id],
    ], $over);
}

describe('the price', function () {
    it('counts whole calendar months, rounded up, from the end of the current subscription', function (string $today, ?string $current, string $new, int $cost) {
        $c = fn (?string $d) => $d ? Carbon::parse($d) : null;

        expect(Credits::months($c($current), Carbon::parse($new), Carbon::parse($today)))->toBe($cost);
    })->with([
        'a month from today' => ['2026-10-09', null, '2026-11-09', 1],
        'a day more is not charged (time zones)' => ['2026-10-09', null, '2026-11-10', 1],
        'two days more is' => ['2026-10-09', null, '2026-11-11', 2],
        'a single day still costs a credit, so it can not be bought free in pieces' => ['2026-10-09', '2026-10-20', '2026-10-21', 1],
        'three months' => ['2026-10-09', null, '2027-01-09', 3],
        'a year' => ['2026-10-09', null, '2027-10-09', 12],
        'from the current end date' => ['2026-10-09', '2026-10-20', '2026-11-20', 1],
        'a subscription that already ended counts from today' => ['2026-10-09', '2026-08-01', '2026-11-09', 1],
        'month ends do not overflow' => ['2026-01-31', null, '2026-02-28', 1],
        'the same date is free' => ['2026-10-09', '2026-10-20', '2026-10-20', 0],
        'a shorter subscription is free' => ['2026-10-09', '2026-12-01', '2026-11-01', 0],
        'a date in the past is free' => ['2026-10-09', null, '2026-09-01', 0],
    ]);
});

describe('a reseller with credits', function () {
    it('pays for a new user and keeps a record', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.store'), newCustomer())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 credit used, 4 left'));

        $user = User::where('username', 'fresh')->sole();
        $entry = CreditTransaction::sole();
        expect($this->reseller->fresh()->credits)->toBe(4)
            ->and($entry->amount)->toBe(-1)->and($entry->balance_after)->toBe(4)
            ->and($entry->reason)->toBe('new_subscription')
            ->and($entry->user_id)->toBe($this->reseller->id)->and($entry->customer_id)->toBe($user->id);
    });

    it('prices a longer subscription by its months', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.store'), newCustomer(['expiration_date' => now()->addMonths(3)->toDateString()]));

        expect($this->reseller->fresh()->credits)->toBe(2)->and(CreditTransaction::sole()->amount)->toBe(-3);
    });

    it('creates nothing and spends nothing without enough credits', function () {
        $this->reseller->forceFill(['credits' => 2])->save();

        $this->actingAs($this->reseller)->post(route('admin.users.store'), newCustomer(['expiration_date' => now()->addMonths(3)->toDateString()]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'needs 3, you have 2'));

        expect(User::where('username', 'fresh')->exists())->toBeFalse()
            ->and($this->reseller->fresh()->credits)->toBe(2)
            ->and(CreditTransaction::count())->toBe(0);
    });

    it('can spend the last credits down to zero, and no further', function () {
        $this->reseller->forceFill(['credits' => 1])->save();
        $this->actingAs($this->reseller);

        $this->post(route('admin.users.store'), newCustomer())->assertSessionHasNoErrors();
        expect($this->reseller->fresh()->credits)->toBe(0);

        $this->post(route('admin.users.store'), newCustomer(['username' => 'second']))->assertSessionHas('error');
        expect(User::where('username', 'second')->exists())->toBeFalse()->and($this->reseller->fresh()->credits)->toBe(0);
    });

    it('has to give an end date, because that is what credits buy', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.store'), newCustomer(['expiration_date' => '']))
            ->assertSessionHasErrors('expiration_date');

        expect(User::where('username', 'fresh')->exists())->toBeFalse();
    });

    it('pays only for the time added when extending a user', function () {
        // cust1 ends in 10 days: one month on top of that is 1 credit
        $this->actingAs($this->reseller)->put(route('admin.users.update', $this->customer), newCustomer([
            'username' => 'cust1', 'expiration_date' => $this->customer->expiration_date->copy()->addMonth()->toDateString(),
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, '1 credit used, 4 left'));

        expect($this->reseller->fresh()->credits)->toBe(4)->and(CreditTransaction::sole()->reason)->toBe('extended_subscription');
    });

    it('pays nothing for keeping or shortening an end date, or for other changes', function () {
        $this->actingAs($this->reseller);
        $payload = newCustomer(['username' => 'cust1']);

        $this->put(route('admin.users.update', $this->customer), array_merge($payload, ['expiration_date' => $this->customer->expiration_date->toDateString()]))->assertSessionHasNoErrors();
        $this->put(route('admin.users.update', $this->customer), array_merge($payload, ['expiration_date' => now()->addDays(3)->toDateString()]))->assertSessionHasNoErrors();
        $this->post(route('admin.users.toggle', $this->customer));

        expect($this->reseller->fresh()->credits)->toBe(5)->and(CreditTransaction::count())->toBe(0);
    });

    it('pays from today for a user whose subscription has ended', function () {
        $this->customer->update(['expiration_date' => now()->subMonths(2)]);

        $this->actingAs($this->reseller)->put(route('admin.users.update', $this->customer), newCustomer([
            'username' => 'cust1', 'expiration_date' => now()->addMonth()->toDateString(),
        ]));

        expect($this->reseller->fresh()->credits)->toBe(4);
    });

    it('refuses an extension it can not pay, and changes nothing', function () {
        $this->reseller->forceFill(['credits' => 0])->save();
        $before = $this->customer->expiration_date->toDateString();

        $this->actingAs($this->reseller)->put(route('admin.users.update', $this->customer), newCustomer([
            'username' => 'cust1', 'expiration_date' => now()->addMonths(6)->toDateString(), 'max_connections' => 1,
        ]))->assertSessionHas('error');

        expect($this->customer->fresh()->expiration_date->toDateString())->toBe($before);
    });

    it('can not change its own credits through any form', function () {
        $this->actingAs($this->reseller)->put(route('admin.users.update', $this->customer), newCustomer(['username' => 'cust1', 'credits' => 999, 'initial_credits' => 999]));
        $this->post(route('admin.users.store'), newCustomer(['credits' => 999]));

        expect($this->reseller->fresh()->credits)->toBeLessThan(5);
    });

    it('sees the price while typing and has no "never" button', function () {
        $this->actingAs($this->reseller)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('id="credit-cost"', false)
            ->assertSee('data-balance="5"', false)
            ->assertDontSee('data-extend="0"', false);
    });
});

describe('a reseller without credits', function () {
    beforeEach(fn () => $this->reseller->forceFill(['credits' => null])->save());

    it('is not limited, as before credits existed', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.store'), newCustomer(['expiration_date' => now()->addYears(3)->toDateString()]))
            ->assertSessionHasNoErrors();
        $this->post(route('admin.users.store'), newCustomer(['username' => 'forever', 'expiration_date' => '']))->assertSessionHasNoErrors();

        expect($this->reseller->fresh()->credits)->toBeNull()->and(CreditTransaction::count())->toBe(0);
    });

    it('does not see a price preview', function () {
        $this->actingAs($this->reseller)->get(route('admin.users.create'))->assertDontSee('id="credit-cost"', false)->assertSee('data-extend="0"', false);
    });
});

describe('what the admin does', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('gives credits, and every change is recorded with who did it', function () {
        $this->post(route('admin.users.credits.store', $this->reseller), ['amount' => 10, 'note' => 'October payment'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '10 credits given to resA'));

        $entry = CreditTransaction::sole();
        expect($this->reseller->fresh()->credits)->toBe(15)
            ->and($entry->amount)->toBe(10)->and($entry->balance_after)->toBe(15)
            ->and($entry->actor_id)->toBe($this->admin->id)->and($entry->note)->toBe('October payment')->and($entry->reason)->toBe('admin_grant');
    });

    it('takes credits back, but never below zero', function () {
        $this->post(route('admin.users.credits.store', $this->reseller), ['amount' => -3])->assertSessionHasNoErrors();
        expect($this->reseller->fresh()->credits)->toBe(2)->and(CreditTransaction::sole()->reason)->toBe('admin_take');

        $this->post(route('admin.users.credits.store', $this->reseller), ['amount' => -3])->assertSessionHas('error');
        expect($this->reseller->fresh()->credits)->toBe(2)->and(CreditTransaction::count())->toBe(1);
    });

    it('rejects nonsense amounts', function (mixed $amount) {
        $this->post(route('admin.users.credits.store', $this->reseller), ['amount' => $amount])->assertSessionHasErrors('amount');

        expect($this->reseller->fresh()->credits)->toBe(5);
    })->with(['zero' => [0], 'text' => ['lots'], 'fraction' => [1.5], 'huge' => [5000000], 'empty' => ['']]);

    it('switches the limit on by giving credits to an unlimited reseller, and off again', function () {
        $this->reseller->forceFill(['credits' => null])->save();

        $this->post(route('admin.users.credits.store', $this->reseller), ['amount' => 4]);
        expect($this->reseller->fresh()->credits)->toBe(4);

        $this->post(route('admin.users.credits.unlimited', $this->reseller))->assertSessionHasNoErrors();
        $entry = CreditTransaction::latest('id')->first();
        expect($this->reseller->fresh()->credits)->toBeNull()
            ->and($entry->reason)->toBe('admin_unlimited')->and($entry->balance_after)->toBeNull();
    });

    it('works only on resellers', function () {
        $this->post(route('admin.users.credits.store', $this->customer), ['amount' => 5])->assertNotFound();
        $this->post(route('admin.users.credits.unlimited', $this->admin))->assertNotFound();
    });

    it('starts a new reseller with credits (0 = limited with nothing yet, empty = unlimited)', function () {
        $base = ['password' => 'secret1', 'status' => 'active', 'role' => 'reseller', 'max_connections' => 1];

        $this->post(route('admin.users.store'), $base + ['username' => 'rich', 'initial_credits' => 20])->assertSessionHasNoErrors();
        $this->post(route('admin.users.store'), $base + ['username' => 'zero', 'initial_credits' => 0])->assertSessionHasNoErrors();
        $this->post(route('admin.users.store'), $base + ['username' => 'free'])->assertSessionHasNoErrors();

        expect(User::where('username', 'rich')->value('credits'))->toBe(20)
            ->and(User::where('username', 'zero')->value('credits'))->toBe(0)
            ->and(User::where('username', 'free')->value('credits'))->toBeNull()
            ->and(CreditTransaction::where('user_id', User::where('username', 'rich')->value('id'))->sole()->note)->toBe('Starting credits');
    });

    it('has no starting-credits field when editing (credits are changed on the reseller\'s page, with a record)', function () {
        $this->get(route('admin.users.edit', $this->reseller))->assertDontSee('name="initial_credits"', false);
        $this->get(route('admin.users.create'))->assertSee('name="initial_credits"', false);

        $this->put(route('admin.users.update', $this->reseller), ['username' => 'resA', 'status' => 'active', 'role' => 'reseller', 'max_connections' => 1, 'initial_credits' => 99])
            ->assertSessionHasErrors('initial_credits');
        expect($this->reseller->fresh()->credits)->toBe(5);
    });

    it('shows the balance and the form on the reseller\'s page', function () {
        $this->get(route('admin.users.show', $this->reseller))->assertSee('Give or take back')->assertSee('Make unlimited');
        $this->get(route('admin.users.index'))->assertSee('5 credits');

        $this->reseller->forceFill(['credits' => null])->save();
        $this->get(route('admin.users.show', $this->reseller))->assertDontSee('Make unlimited');
        $this->get(route('admin.users.index'))->assertSee('Unlimited credits');
    });
});

describe('who sees the credits', function () {
    it('keeps the credit controls away from resellers', function () {
        $this->actingAs($this->reseller);

        $this->post(route('admin.users.credits.store', $this->reseller), ['amount' => 100])->assertForbidden();
        $this->post(route('admin.users.credits.unlimited', $this->reseller))->assertForbidden();
        expect($this->reseller->fresh()->credits)->toBe(5);
    });

    it('shows a reseller their own balance and history only', function () {
        $other = User::factory()->create(['username' => 'resB', 'role' => 'reseller', 'credits' => 50, 'expiration_date' => null]);
        app(CreditService::class)->adjust($this->admin, $this->reseller, 10, 'mine');
        app(CreditService::class)->adjust($this->admin, $other, 7, 'theirs');

        $page = $this->actingAs($this->reseller)->get(route('admin.credits.index'))->assertOk();
        $page->assertSee('mine')->assertDontSee('theirs')->assertDontSee('resB')->assertSee('Your credits');

        // asking for another reseller's history changes nothing
        $this->get(route('admin.credits.index', ['reseller' => $other->id]))->assertSee('mine')->assertDontSee('theirs');
        $this->get(route('admin.dashboard'))->assertSee('Your credits');
    });

    it('shows an admin everyone, or one reseller', function () {
        $other = User::factory()->create(['username' => 'resB', 'role' => 'reseller', 'credits' => 50, 'expiration_date' => null]);
        app(CreditService::class)->adjust($this->admin, $this->reseller, 10, 'mine');
        app(CreditService::class)->adjust($this->admin, $other, 7, 'theirs');
        $this->actingAs($this->admin);

        $this->get(route('admin.credits.index'))->assertSee('mine')->assertSee('theirs')->assertSee('resA')->assertSee('resB');
        $this->get(route('admin.credits.index', ['reseller' => $other->id]))->assertSee('theirs')->assertDontSee('mine');
    });

    it('is not for subscribers or guests', function () {
        $this->get(route('admin.credits.index'))->assertRedirect(route('login'));
        $this->actingAs($this->customer)->get(route('admin.credits.index'))->assertRedirect();
    });

    it('keeps the history when a user is deleted, without the name', function () {
        $this->actingAs($this->reseller)->post(route('admin.users.store'), newCustomer());
        $user = User::where('username', 'fresh')->sole();

        $this->delete(route('admin.users.destroy', $user));

        expect(CreditTransaction::sole()->customer_id)->toBeNull();
        $this->get(route('admin.credits.index'))->assertOk()->assertSee('New subscription');
    });
});
