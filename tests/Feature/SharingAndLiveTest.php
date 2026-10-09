<?php

use App\Mail\StreamAlertsMail;
use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\StreamAlert;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\SharingDetectionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    config(['iptv.sharing_ips' => 3, 'iptv.sharing_hours' => 24]);

    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->reseller = User::factory()->create(['username' => 'resA', 'role' => 'reseller']);
    $this->other = User::factory()->create(['username' => 'resB', 'role' => 'reseller']);
    $this->user = User::factory()->create(['username' => 'maria', 'owner_id' => $this->reseller->id]);
    $this->channel = Channel::create(['name' => 'Rai 1', 'stream_url' => 'http://x.test/1', 'is_active' => true]);
});

function plays(User $user, array $ips, string $when = 'now'): void
{
    foreach ($ips as $ip) {
        StreamLog::create(['user_id' => $user->id, 'channel_id' => test()->channel->id, 'ip' => $ip, 'user_agent' => 'VLC', 'started_at' => Carbon::parse($when)]);
    }
}

describe('sharing alerts', function () {
    it('raises an alert once a day for a login used from enough different addresses', function () {
        plays($this->user, ['1.1.1.1', '2.2.2.2', '3.3.3.3', '3.3.3.3']);

        expect(app(SharingDetectionService::class)->detect())->toBe(1);

        $alert = StreamAlert::first();
        expect($alert->type)->toBe(StreamAlert::TYPE_SHARING)->and($alert->user_id)->toBe($this->user->id)
            ->and($alert->message())->toBe('maria used 3 different addresses in 24 hours: the login may be shared')
            ->and($alert->isProblem())->toBeTrue();

        // Not again today, again tomorrow
        expect(app(SharingDetectionService::class)->detect())->toBe(0);
        Carbon::setTestNow('2026-10-16 13:00:00');
        plays($this->user, ['1.1.1.1', '2.2.2.2', '3.3.3.3'], '2026-10-16 12:30:00');
        expect(app(SharingDetectionService::class)->detect())->toBe(1);
    });

    it('leaves alone logins below the limit, old plays, admins, resellers and disabled users', function () {
        $few = User::factory()->create(['owner_id' => $this->reseller->id]);
        $old = User::factory()->create(['owner_id' => $this->reseller->id]);
        $off = User::factory()->create(['owner_id' => $this->reseller->id, 'status' => 'disabled']);
        plays($few, ['1.1.1.1', '2.2.2.2']);
        plays($old, ['1.1.1.1', '2.2.2.2', '3.3.3.3'], '2026-10-13 12:00:00');
        plays($off, ['1.1.1.1', '2.2.2.2', '3.3.3.3']);
        plays($this->reseller, ['1.1.1.1', '2.2.2.2', '3.3.3.3']);

        expect(app(SharingDetectionService::class)->detect())->toBe(0)->and(StreamAlert::count())->toBe(0);
    });

    it('can be switched off, and the command runs hourly', function () {
        plays($this->user, ['1.1.1.1', '2.2.2.2', '3.3.3.3']);
        config(['iptv.sharing_ips' => 0]);

        expect(app(SharingDetectionService::class)->detect())->toBe(0);

        config(['iptv.sharing_ips' => 3]);
        $this->artisan('iptv:detect-sharing')->expectsOutput('Alerts raised: 1')->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'iptv:detect-sharing'));
        expect($scheduled)->not->toBeNull()->and($scheduled->expression)->toBe('0 * * * *');
    });

    it('reaches the admin, the reseller of that user, and the email digest', function () {
        plays($this->user, ['1.1.1.1', '2.2.2.2', '3.3.3.3']);
        app(SharingDetectionService::class)->detect();

        $this->actingAs($this->admin)->get(route('admin.alerts.index'))->assertSee('maria used 3 different addresses');
        $this->actingAs($this->reseller)->get(route('admin.alerts.index'))->assertSee('maria used 3 different addresses');
        $this->actingAs($this->other)->get(route('admin.alerts.index'))->assertDontSee('maria used');

        expect((new StreamAlertsMail(StreamAlert::all()))->groups())->toHaveKey('subscriptions');
    });
});

describe('the user page', function () {
    it('lists the addresses with plays and flags many of them', function () {
        plays($this->user, ['9.9.9.9', '9.9.9.9']);
        plays($this->user, ['1.1.1.1'], '2026-10-14 08:00:00');
        plays($this->user, ['8.8.8.8'], '2026-10-01 08:00:00');   // older than 7 days

        $page = $this->actingAs($this->reseller)->get(route('admin.users.show', $this->user))->assertOk()->assertSee('Where this user watches from')->assertSee('9.9.9.9')->assertSee('1.1.1.1');
        expect(array_column(app(SharingDetectionService::class)->addresses($this->user), 'ip'))->toBe(['9.9.9.9', '1.1.1.1']);   // newest first, nothing older than 7 days
        $page->assertDontSee('addresses in 24 hours');

        plays($this->user, ['2.2.2.2', '3.3.3.3']);
        $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))->assertSee('3 addresses in 24 hours');
    });

    it('is left out when there are no plays', function () {
        $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))->assertDontSee('Where this user watches from');
    });
});

describe('live', function () {
    function liveSession(User $user, string $when = 'now', string $ip = '5.5.5.5'): ActiveSession
    {
        return ActiveSession::create(['user_id' => $user->id, 'channel_id' => test()->channel->id, 'ip' => $ip, 'user_agent' => 'TiviMate', 'started_at' => Carbon::parse($when), 'last_activity_at' => Carbon::parse($when)]);
    }

    it('shows the devices watching now, to an admin all and to a reseller their own', function () {
        $theirs = User::factory()->create(['username' => 'zed', 'owner_id' => $this->other->id]);
        liveSession($this->user);
        liveSession($this->user, ip: '6.6.6.6');
        liveSession($theirs, ip: '7.7.7.7');
        liveSession(User::factory()->create(['username' => 'gone', 'owner_id' => $this->reseller->id]), '2026-10-15 11:00:00');   // stopped playing an hour ago

        $this->actingAs($this->admin)->get(route('admin.live.index'))->assertOk()->assertSee('maria')->assertSee('zed')->assertSee('5.5.5.5')->assertSee('7.7.7.7')->assertDontSee('gone')
            ->assertSee('Devices watching')->assertSee('Rai 1')->assertSee('3 devices');

        $this->actingAs($this->reseller)->get(route('admin.live.index'))->assertSee('maria')->assertDontSee('zed')->assertDontSee('7.7.7.7');
    });

    it('has a disconnect button that works for the reseller\'s own device', function () {
        $session = liveSession($this->user);

        $this->actingAs($this->reseller)->get(route('admin.live.index'))->assertSee(route('admin.users.sessions.disconnect', [$this->user, $session]), false);
        $this->actingAs($this->reseller)->post(route('admin.users.sessions.disconnect', [$this->user, $session]))->assertRedirect();

        expect(ActiveSession::count())->toBe(0);
    });

    it('says when nobody is watching, is in the menu, and is for staff only', function () {
        $this->actingAs($this->admin)->get(route('admin.live.index'))->assertSee('Nobody is watching right now');
        $this->actingAs($this->reseller)->get(route('admin.dashboard'))->assertSee(route('admin.live.index'), false);

        auth()->logout();
        $this->get(route('admin.live.index'))->assertRedirect(route('login'));
        $this->actingAs($this->user)->get(route('admin.live.index'))->assertRedirect(route('login'));
    });
});
