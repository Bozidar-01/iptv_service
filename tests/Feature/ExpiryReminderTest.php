<?php

use App\Mail\StreamAlertsMail;
use App\Models\StreamAlert;
use App\Models\User;
use App\Services\SubscriptionReminderService;
use Illuminate\Console\Scheduling\Schedule;

function subscriber(array $attributes = []): User
{
    return User::factory()->create(array_merge(['role' => 'user', 'status' => 'active'], $attributes));
}

it('reminds once before a subscription ends and once when it has ended', function () {
    config(['iptv.expiry_reminder_days' => 3]);
    $user = subscriber(['username' => 'marko', 'expiration_date' => now()->addDays(2)]);
    $service = app(SubscriptionReminderService::class);

    expect($service->run())->toBe(1)->and($service->run())->toBe(0);
    expect(StreamAlert::sole())->type->toBe('expiring')->user_id->toBe($user->id)
        ->detail->toBe($user->expiration_date->toDateString())
        ->and(StreamAlert::sole()->message())->toBe('Subscription of marko ends on '.$user->expiration_date->translatedFormat('j M Y'));

    $this->travel(3)->days();
    expect($service->run())->toBe(1)->and($service->run())->toBe(0);
    expect(StreamAlert::latest('id')->first())->type->toBe('expired');
});

it('reminds again after the subscription is extended', function () {
    $user = subscriber(['expiration_date' => now()->addDay()]);
    app(SubscriptionReminderService::class)->run();

    $user->update(['expiration_date' => now()->addDays(2)]);
    app(SubscriptionReminderService::class)->run();

    expect(StreamAlert::where('type', 'expiring')->count())->toBe(2);
});

it('skips users that are far from expiry, never expire, are disabled, admins, or expired long ago', function () {
    subscriber(['expiration_date' => now()->addMonth()]);
    subscriber(['expiration_date' => null]);
    subscriber(['status' => 'disabled', 'expiration_date' => now()->addDay()]);
    User::factory()->admin()->create(['expiration_date' => now()->addDay()]);
    subscriber(['expiration_date' => now()->subMonth()]);

    expect(app(SubscriptionReminderService::class)->run())->toBe(0);
});

it('is scheduled daily and shown in the email and the panel', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'iptv:expiry-reminders'));
    expect($event?->expression)->toBe('0 8 * * *');

    $user = subscriber(['username' => 'ana', 'expiration_date' => now()->addDay()]);
    $this->artisan('iptv:expiry-reminders')->expectsOutputToContain('Reminders created: 1');

    $mail = new StreamAlertsMail(StreamAlert::all());
    expect(array_keys($mail->groups()))->toBe(['subscriptions']);
    $mail->assertSeeInHtml('Subscription of ana ends on');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.alerts.index'))
        ->assertSee(route('admin.users.show', $user), false);
});
