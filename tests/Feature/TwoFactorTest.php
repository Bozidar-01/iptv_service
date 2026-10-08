<?php

use App\Http\Controllers\Auth\AuthController;
use App\Models\LoginEvent;
use App\Models\Source;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** An admin with two-factor turned on; returns [admin, secret, recovery codes]. */
function adminWithTwoFactor(): array
{
    $admin = User::factory()->admin()->create();
    $service = app(TwoFactorService::class);
    $secret = $service->begin($admin);
    $codes = $service->confirm($admin, Totp::code($secret, Totp::step()));

    // The setup code is used up: move the "last used" step back so the next code is accepted
    $admin->forceFill(['two_factor_last_step' => Totp::step() - 5])->save();

    return [$admin->fresh(), $secret, $codes];
}

describe('setup', function () {
    it('shows the QR code and turns on after a right code', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->post(route('admin.security.start'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        expect(app(TwoFactorService::class)->pendingSecret($admin->fresh()))->toBeNull();

        $this->post(route('admin.security.start'), ['password' => 'password'])->assertRedirect(route('admin.security.index'));
        $secret = app(TwoFactorService::class)->pendingSecret($admin->fresh());

        $this->get(route('admin.security.index'))->assertOk()->assertSee('<svg', false)->assertSee($secret);
        expect($admin->fresh()->two_factor_confirmed_at)->toBeNull();

        $this->post(route('admin.security.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        expect($admin->fresh()->two_factor_confirmed_at)->toBeNull();

        $this->post(route('admin.security.confirm'), ['code' => Totp::code($secret, Totp::step())])
            ->assertRedirect(route('admin.security.index'))
            ->assertSessionHas('recovery_codes', fn ($codes) => count($codes) === 8);

        expect($admin->fresh()->two_factor_confirmed_at)->not->toBeNull()
            ->and(LoginEvent::where('event', 'two_factor_enabled')->count())->toBe(1);
    });

    it('stores the secret encrypted and the recovery codes only as hashes', function () {
        [$admin, $secret, $codes] = adminWithTwoFactor();
        $raw = DB::table('users')->where('id', $admin->id)->first();

        expect($raw->two_factor_secret)->not->toContain($secret)
            ->and(Crypt::decryptString($raw->two_factor_secret))->toBe($secret)
            ->and($raw->two_factor_recovery_codes)->not->toContain($codes[0]);
    });

    it('needs the password to create new recovery codes or turn it off', function () {
        [$admin] = adminWithTwoFactor();
        $this->actingAs($admin);

        $this->post(route('admin.security.disable'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('admin.security.recovery-codes'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        expect(app(TwoFactorService::class)->isEnabled($admin->fresh()))->toBeTrue();

        $old = $admin->fresh()->two_factor_recovery_codes;
        $this->post(route('admin.security.recovery-codes'), ['password' => 'password'])
            ->assertSessionHas('recovery_codes', fn ($codes) => count($codes) === 8);
        expect($admin->fresh()->two_factor_recovery_codes)->not->toBe($old);

        $this->post(route('admin.security.disable'), ['password' => 'password'])->assertRedirect();
        expect(app(TwoFactorService::class)->isEnabled($admin->fresh()))->toBeFalse()
            ->and($admin->fresh()->two_factor_secret)->toBeNull();
    });

    it('is for signed-in admins only', function () {
        $this->get(route('admin.security.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.security.index'))->assertRedirect();
    });
});

describe('sign-in', function () {
    it('signs in straight away without two-factor', function () {
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['username' => $admin->username, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    });

    it('asks for a code after the right password and signs in with it', function () {
        [$admin, $secret] = adminWithTwoFactor();

        $this->post('/login', ['username' => $admin->username, 'password' => 'password'])->assertRedirect(route('two-factor.show'));
        $this->assertGuest();
        $this->get(route('two-factor.show'))->assertOk()->assertSee('authenticator');

        $this->post(route('two-factor.verify'), ['code' => Totp::code($secret, Totp::step())])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        expect(LoginEvent::where('event', 'success')->where('user_id', $admin->id)->count())->toBe(1);
    });

    it('does not sign in with a wrong code or a wrong password', function () {
        [$admin] = adminWithTwoFactor();

        $this->post('/login', ['username' => $admin->username, 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertRedirect(route('login'));

        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertSessionHasErrors('code');

        $this->assertGuest();
        expect(LoginEvent::pluck('event')->all())->toBe(['failed', 'two_factor_failed']);
    });

    it('accepts a code only once', function () {
        [$admin, $secret] = adminWithTwoFactor();
        $code = Totp::code($secret, Totp::step());

        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->post('/logout');

        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('accepts each recovery code once', function () {
        [$admin, , $codes] = adminWithTwoFactor();

        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => strtoupper($codes[0])])->assertRedirect(route('admin.dashboard'));
        $this->post('/logout');
        expect(app(TwoFactorService::class)->recoveryCodesLeft($admin->fresh()))->toBe(7);

        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('stops guessing codes after five tries', function () {
        [$admin, $secret] = adminWithTwoFactor();
        RateLimiter::clear('two-factor:'.$admin->id.'|127.0.0.1');

        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);
        foreach (range(1, 5) as $i) {
            $this->post(route('two-factor.verify'), ['code' => '00000'.$i])->assertSessionHasErrors('code');
        }

        // Even the right code is refused now
        $this->post(route('two-factor.verify'), ['code' => Totp::code($secret, Totp::step())])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('lets the code step expire', function () {
        [$admin, $secret] = adminWithTwoFactor();
        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);

        $this->travel(6)->minutes();

        $this->post(route('two-factor.verify'), ['code' => Totp::code($secret, Totp::step())])->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('does not show the code form to someone who has not given a password', function () {
        $this->get(route('two-factor.show'))->assertRedirect(route('login'));
    });
});

describe('sign-in history', function () {
    it('records successes, wrong passwords and subscribers trying the panel', function () {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->post('/login', ['username' => $admin->username, 'password' => 'nope']);
        $this->post('/login', ['username' => 'ghost', 'password' => 'nope']);
        $this->post('/login', ['username' => $user->username, 'password' => 'password']);
        $this->post('/login', ['username' => $admin->username, 'password' => 'password']);

        expect(LoginEvent::orderBy('id')->pluck('event')->all())->toBe(['failed', 'failed', 'denied', 'success'])
            ->and(LoginEvent::where('username', 'ghost')->sole()->user_id)->toBeNull();

        $this->get(route('admin.security.index'))->assertOk()->assertSee('Wrong password')->assertSee('ghost');
    });

    it('deletes events older than the keep time', function () {
        LoginEvent::create(['username' => 'old', 'event' => 'success'])->forceFill(['created_at' => now()->subDays(181)])->save();
        LoginEvent::create(['username' => 'new', 'event' => 'success']);

        $this->artisan('model:prune', ['--model' => [LoginEvent::class]])->assertSuccessful();

        expect(LoginEvent::pluck('username')->all())->toBe(['new']);
    });
});

it('stores source addresses encrypted', function () {
    $source = Source::create(['name' => 'Provider', 'type' => 'url', 'url' => 'http://p.test/get.php?username=u&password=secret']);
    $raw = DB::table('sources')->where('id', $source->id)->value('url');

    expect($raw)->not->toContain('secret')->not->toContain('p.test')
        ->and($source->fresh()->url)->toBe('http://p.test/get.php?username=u&password=secret');
});

describe('hardening', function () {
    it('ends remembered logins when two-factor is turned on or off', function () {
        $admin = User::factory()->admin()->create(['remember_token' => 'old-remember-token']);
        $this->actingAs($admin);
        $service = app(TwoFactorService::class);
        $secret = $service->begin($admin);

        $this->post(route('admin.security.confirm'), ['code' => Totp::code($secret, Totp::step())]);
        expect($admin->fresh()->remember_token)->not->toBe('old-remember-token');

        $token = $admin->fresh()->remember_token;
        $this->post(route('admin.security.disable'), ['password' => 'password']);
        expect($admin->fresh()->remember_token)->not->toBe($token);
    });

    it('lets only one of two requests with the same code through', function () {
        [$admin, $secret] = adminWithTwoFactor();
        $code = Totp::code($secret, Totp::step());
        $stale = User::find($admin->id); // both requests read the same old state

        expect(app(TwoFactorService::class)->verify($admin, $code))->toBeTrue()
            ->and(app(TwoFactorService::class)->verify($stale, $code))->toBeFalse();
    });

    it('stops trying many usernames from one address', function () {
        foreach (range(1, AuthController::MAX_ATTEMPTS_PER_IP) as $i) {
            $this->post('/login', ['username' => "ghost{$i}", 'password' => 'nope'])->assertSessionHasErrors('username');
        }

        $admin = User::factory()->admin()->create();
        $this->post('/login', ['username' => $admin->username, 'password' => 'password'])->assertSessionHasErrors('username');
        $this->assertGuest();
    });

    it('counts a subscriber guessing the panel login as a failed attempt', function () {
        $user = User::factory()->create();

        foreach (range(1, AuthController::MAX_ATTEMPTS) as $i) {
            $this->post('/login', ['username' => $user->username, 'password' => 'password']);
        }

        $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertSessionHasErrors('username');
        expect(session('errors')->first('username'))->toContain('Too many sign-in attempts');
    });
});
