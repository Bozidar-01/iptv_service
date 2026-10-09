<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserBulkService;
use App\Services\UserCleanupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    config(['iptv.trial_keep_days' => 7, 'iptv.expired_disable_days' => 60]);
});

describe('old trials', function () {
    it('are deleted a week after they ended, and nothing else is', function () {
        $old = User::factory()->create(['is_trial' => true, 'expiration_date' => '2026-10-01']);
        $recent = User::factory()->create(['is_trial' => true, 'expiration_date' => '2026-10-12']);
        $running = User::factory()->create(['is_trial' => true, 'expiration_date' => '2026-10-16']);
        $paid = User::factory()->create(['is_trial' => false, 'expiration_date' => '2026-10-01']);
        $reseller = User::factory()->create(['role' => 'reseller', 'is_trial' => true, 'expiration_date' => '2026-10-01']);

        $result = app(UserCleanupService::class)->run();

        expect($result['deleted'])->toBe(1)->and(User::find($old->id))->toBeNull()
            ->and(User::whereIn('id', [$recent->id, $running->id, $paid->id, $reseller->id])->count())->toBe(4);
    });

    it('are kept when the setting is 0', function () {
        config(['iptv.trial_keep_days' => 0]);
        User::factory()->create(['is_trial' => true, 'expiration_date' => '2026-01-01']);

        expect(app(UserCleanupService::class)->run()['deleted'])->toBe(0)->and(User::count())->toBe(1);
    });
});

describe('long-expired users', function () {
    it('are switched off after 60 days, only active subscribers', function () {
        $long = User::factory()->create(['expiration_date' => '2026-07-01', 'status' => 'active']);
        $soon = User::factory()->create(['expiration_date' => '2026-09-20', 'status' => 'active']);
        $never = User::factory()->create(['expiration_date' => null, 'status' => 'active']);
        $reseller = User::factory()->create(['role' => 'reseller', 'expiration_date' => '2026-07-01', 'status' => 'active']);
        $admin = User::factory()->admin()->create(['expiration_date' => '2026-07-01']);

        expect(app(UserCleanupService::class)->run()['disabled'])->toBe(1)
            ->and($long->fresh()->status)->toBe('disabled')
            ->and($soon->fresh()->status)->toBe('active')->and($never->fresh()->status)->toBe('active')->and($reseller->fresh()->status)->toBe('active');
    });

    it('are left alone when the setting is 0', function () {
        config(['iptv.expired_disable_days' => 0]);
        $user = User::factory()->create(['expiration_date' => '2020-01-01', 'status' => 'active']);

        app(UserCleanupService::class)->run();

        expect($user->fresh()->status)->toBe('active');
    });
});

it('writes what it did to the audit log as the system', function () {
    User::factory()->create(['username' => 'trial1', 'is_trial' => true, 'expiration_date' => '2026-10-01']);
    User::factory()->create(['username' => 'longgone', 'expiration_date' => '2026-07-01']);

    app(UserCleanupService::class)->run();

    $entries = AuditLog::orderBy('id')->get();
    expect($entries->pluck('action')->all())->toBe([AuditLog::USERS_AUTO_DELETED, AuditLog::USERS_AUTO_DISABLED])
        ->and($entries[0]->actor_name)->toBe('system')->and($entries[0]->actor_id)->toBeNull()
        ->and($entries[0]->summary())->toBe('1 user · trial1')->and($entries[1]->summary())->toBe('1 user · longgone');

    // A quiet day writes nothing
    app(UserCleanupService::class)->run();
    expect(AuditLog::count())->toBe(2);
});

it('runs from the command and the daily schedule', function () {
    User::factory()->create(['is_trial' => true, 'expiration_date' => '2026-10-01']);

    $this->artisan('iptv:cleanup-users')->expectsOutput('Trials deleted: 1, users switched off: 0')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'iptv:cleanup-users'));
    expect($event?->expression)->toBe('0 5 * * *');
});

it('turns a switched-off user back on when they are renewed', function () {
    $admin = User::factory()->admin()->create();
    $single = User::factory()->create(['expiration_date' => '2026-07-01', 'status' => 'disabled']);
    $bulk = User::factory()->create(['expiration_date' => '2026-07-01', 'status' => 'disabled']);

    $this->actingAs($admin)->post(route('admin.users.renew', $single), ['months' => 1]);
    app(UserBulkService::class)->apply($admin, 'extend', User::whereKey($bulk->id), ['months' => 1]);

    expect($single->fresh()->status)->toBe('active')->and($single->fresh()->expiration_date->toDateString())->toBe('2026-11-15')
        ->and($bulk->fresh()->status)->toBe('active');
});
