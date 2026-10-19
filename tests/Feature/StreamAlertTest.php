<?php

use App\Mail\StreamAlertsMail;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamAlert;
use App\Models\User;
use App\Services\SourceSyncService;
use App\Services\StreamCheckService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::fake([
        'live.test/*' => fn () => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-MEDIA-SEQUENCE:1\n#EXTINF:6.0,\nseg1.ts\n", 200),
        'gone.test/*' => fn () => Http::response('', 404),
    ]);
});

function watchedChannel(string $url, array $attributes = []): Channel
{
    return Channel::create(array_merge(['name' => 'Kanal 5', 'stream_url' => $url, 'is_active' => true, 'alert_on_down' => true], $attributes));
}

function check(Channel ...$channels): void
{
    app(StreamCheckService::class)->checkChannels(collect($channels)->pluck('id')->all());
}

it('alerts once when a watched channel stops working, with the reason', function () {
    $channel = watchedChannel('http://gone.test/a');

    check($channel);
    check($channel);

    expect(StreamAlert::count())->toBe(1)
        ->and(StreamAlert::first())->type->toBe('down')->channel_id->toBe($channel->id)->name->toBe('Kanal 5')->detail->toBe('http:404')
        ->and(StreamAlert::first()->message())->toBe('Kanal 5 stopped working: Stream not found (HTTP 404)');
});

it('alerts when a watched channel works again', function () {
    $channel = watchedChannel('http://live.test/a', ['is_active' => false, 'auto_disabled' => true, 'stream_status' => 'offline']);

    check($channel);

    expect(StreamAlert::sole()->type)->toBe('up');
});

it('does not alert for working or unwatched channels', function () {
    check(watchedChannel('http://live.test/a'), watchedChannel('http://gone.test/a', ['alert_on_down' => false]));

    expect(StreamAlert::count())->toBe(0);
});

it('watches every channel of a watched source', function () {
    $source = Source::create(['name' => 'P', 'type' => 'url', 'url' => 'http://p.test', 'alert_on_down' => true]);
    $channel = watchedChannel('http://gone.test/a', ['source_id' => $source->id, 'alert_on_down' => false]);

    check($channel);

    expect(StreamAlert::sole())->type->toBe('down')->source_id->toBe($source->id);
});

it('only checks watched channels with --watched', function () {
    Queue::fake();
    watchedChannel('http://live.test/a');
    watchedChannel('http://live.test/b', ['alert_on_down' => false]);

    $this->artisan('iptv:check-streams', ['--watched' => true])->expectsOutputToContain('Queued a check of 1 channels.');
});

it('alerts when a watched source fails to import', function () {
    $source = Source::create(['name' => 'Provider', 'type' => 'url', 'url' => 'http://gone.test/list.m3u', 'alert_on_down' => true]);

    expect(fn () => app(SourceSyncService::class)->sync($source))->toThrow(RuntimeException::class);

    expect(StreamAlert::sole())->type->toBe('sync_failed')->name->toBe('Provider');
});

describe('email', function () {
    beforeEach(fn () => Mail::fake());

    it('emails new alerts as one message and only once', function () {
        config(['iptv.alert_mail_to' => ['me@example.com']]);
        StreamAlert::create(['type' => 'down', 'name' => 'Kanal 5']);
        StreamAlert::create(['type' => 'down', 'name' => 'Sitel']);

        $this->artisan('iptv:send-alerts')->assertSuccessful();
        $this->artisan('iptv:send-alerts')->assertSuccessful();

        Mail::assertSent(StreamAlertsMail::class, 1);
        Mail::assertSent(StreamAlertsMail::class, fn ($mail) => $mail->hasTo('me@example.com')
            && $mail->alerts->count() === 2
            && $mail->envelope()->subject === config('app.name').': 2 channels or sources are not working');
        expect(StreamAlert::whereNull('mailed_at')->count())->toBe(0);
    });

    it('skips alerts older than a day', function () {
        config(['iptv.alert_mail_to' => ['me@example.com']]);
        $old = StreamAlert::create(['type' => 'down', 'name' => 'Old']);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('iptv:send-alerts')->assertSuccessful();

        Mail::assertNothingSent();
        expect($old->fresh()->mailed_at)->not->toBeNull();
    });

    it('sends nothing without a recipient', function () {
        config(['iptv.alert_mail_to' => []]);
        StreamAlert::create(['type' => 'down', 'name' => 'Kanal 5']);

        $this->artisan('iptv:send-alerts')->assertSuccessful();

        Mail::assertNothingSent();
    });

    it('sends a test email', function () {
        config(['iptv.alert_mail_to' => ['me@example.com']]);

        $this->artisan('iptv:send-alerts', ['--test' => true])->assertSuccessful();

        Mail::assertSent(StreamAlertsMail::class, fn ($mail) => $mail->alerts->isEmpty());
    });

    it('renders the email', function () {
        $mail = new StreamAlertsMail(collect([StreamAlert::create(['type' => 'down', 'name' => 'Kanal 5'])]));

        $mail->assertSeeInHtml('1 problem needs your attention')
            ->assertDontSeeInHtml('HTTP 404')
            ->assertSeeInHtml('1 channel stopped working')
            ->assertSeeInHtml('Kanal 5')
            ->assertSeeInText('- Kanal 5');
    });

    it('shows the reason and background job problems', function () {
        $mail = new StreamAlertsMail(collect([
            StreamAlert::create(['type' => 'down', 'name' => 'Telma', 'detail' => 'timeout']),
            StreamAlert::create(['type' => 'jobs_failed', 'name' => 'Queue', 'detail' => '3']),
        ]));

        expect(array_keys($mail->groups()))->toBe(['system', 'down']);
        $mail->assertSeeInOrderInHtml(['Background tasks', '3 background jobs failed', 'Telma', 'No answer in time'])
            ->assertSeeInText('Telma, ')
            ->assertSeeInText(': No answer in time');
    });

    it('groups alerts by type, problems first', function () {
        $mail = new StreamAlertsMail(collect([
            StreamAlert::create(['type' => 'up', 'name' => 'Sitel']),
            StreamAlert::create(['type' => 'down', 'name' => 'Telma']),
            StreamAlert::create(['type' => 'sync_failed', 'name' => 'Provider']),
        ]));

        expect(array_keys($mail->groups()))->toBe(['sync_failed', 'down', 'up']);
        $mail->assertSeeInOrderInHtml(['1 source failed to import', 'Provider', '1 channel stopped working', 'Telma', '1 channel is working again', 'Sitel']);
    });

    it('renders the test email', function () {
        (new StreamAlertsMail(collect()))->assertSeeInHtml('Email alerts are working');
    });
});

describe('admin', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('shows unread alerts in the header and marks them read', function () {
        StreamAlert::create(['type' => 'down', 'name' => 'Kanal 5']);

        $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee('notif-count', false)->assertSee('Kanal 5 stopped working');

        $this->from(route('admin.dashboard'))->post(route('admin.alerts.read'))->assertRedirect(route('admin.dashboard'));

        expect(StreamAlert::unread()->count())->toBe(0);
        $this->get(route('admin.dashboard'))->assertDontSee('notif-count', false);
    });

    it('lists alerts', function () {
        StreamAlert::create(['type' => 'up', 'name' => 'Sitel']);

        $this->get(route('admin.alerts.index'))->assertOk()->assertSee('Sitel is working again');
    });

    it('shows watched channels that do not work on the dashboard', function () {
        watchedChannel('http://gone.test/a', ['name' => 'Telma', 'stream_status' => 'offline', 'stream_checked_at' => now()]);
        watchedChannel('http://live.test/a', ['name' => 'Alfa', 'stream_status' => 'online']);

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Telma')->assertDontSee('Alfa');
    });

    it('turns alerts on from the channel form and the source form', function () {
        $channel = Channel::create(['name' => 'X', 'stream_url' => 'http://live.test/x', 'is_active' => true]);
        $this->put(route('admin.channels.update', $channel), ['name' => 'X', 'stream_url' => 'http://live.test/x', 'is_active' => '1', 'alert_on_down' => '1']);
        expect($channel->fresh()->alert_on_down)->toBeTrue();

        Queue::fake();
        $this->post(route('admin.sources.store'), ['name' => 'P', 'type' => 'url', 'url' => 'http://p.test/list.m3u', 'alert_on_down' => '1']);
        expect(Source::where('name', 'P')->value('alert_on_down'))->toBeTrue();
    });

    it('turns alerts on and off in bulk', function () {
        $channel = Channel::create(['name' => 'X', 'stream_url' => 'http://live.test/x', 'is_active' => true]);

        $this->post(route('admin.channels.bulk'), ['action' => 'alerts_on', 'scope' => 'selected', 'ids' => [$channel->id]])
            ->assertSessionHas('success', 'Alerts turned on for 1 channel.');
        expect($channel->fresh()->alert_on_down)->toBeTrue();

        $this->get(route('admin.channels.index', ['status' => 'watched']))->assertSee('watch-icon', false);

        $this->post(route('admin.channels.bulk'), ['action' => 'alerts_off', 'scope' => 'selected', 'ids' => [$channel->id]]);
        expect($channel->fresh()->alert_on_down)->toBeFalse();
    });
});
