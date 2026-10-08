<?php

use App\Mail\SubscriptionReminderMail;
use App\Models\StreamAlert;
use App\Models\User;
use App\Services\SubscriptionReminderService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

function reminderUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'username' => 'marko', 'email' => 'marko@example.com', 'expiration_date' => now()->addDays(2),
    ], $attributes));
}

beforeEach(function () {
    config(['iptv.expiry_reminder_days' => 3, 'iptv.reminder_email_users' => true, 'iptv.renewal_contact' => '']);
    Mail::fake();
});

it('emails a subscriber with an address once per reminder', function () {
    $user = reminderUser();
    $service = app(SubscriptionReminderService::class);

    $service->run();
    $service->run();

    Mail::assertSent(SubscriptionReminderMail::class, 1);
    Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo('marko@example.com') && $mail->type === 'expiring' && $mail->user->is($user));
    expect(StreamAlert::sole()->user_mailed_at)->not->toBeNull();
});

it('emails again when the subscription has ended, and after an extension', function () {
    $user = reminderUser();
    $service = app(SubscriptionReminderService::class);
    $service->run();

    $this->travel(3)->days();
    $service->run();
    Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->type === 'expired');

    $user->update(['expiration_date' => now()->addDay()]);
    $service->run();
    Mail::assertSent(SubscriptionReminderMail::class, 3);
});

it('sends nothing to subscribers without an address, to admins, or when switched off', function () {
    reminderUser(['username' => 'no-mail', 'email' => null]);
    reminderUser(['username' => 'empty-mail', 'email' => '']);
    app(SubscriptionReminderService::class)->run();
    Mail::assertNothingSent();

    config(['iptv.reminder_email_users' => false]);
    reminderUser(['username' => 'switched-off']);
    app(SubscriptionReminderService::class)->run();
    Mail::assertNothingSent();

    // The admins' own alerts are created either way
    expect(StreamAlert::count())->toBe(3);
});

it('keeps the admin alert even when the email fails, and tries again on the next run', function () {
    // The real mailer with the array transport (not the fake from beforeEach)
    config(['mail.default' => 'array']);
    Mail::swap(new MailManager(app()));
    reminderUser();
    $service = app(SubscriptionReminderService::class);

    Event::listen(MessageSending::class, fn () => throw new RuntimeException('SMTP is down'));
    expect($service->run())->toBe(1)->and($service->mailed)->toBe(0);
    expect(StreamAlert::sole()->user_mailed_at)->toBeNull();

    Event::forget(MessageSending::class);
    expect($service->run())->toBe(0)->and($service->mailed)->toBe(1);
    expect(StreamAlert::sole()->user_mailed_at)->not->toBeNull()
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

describe('the email', function () {
    it('names the date and links to the subscriber page', function () {
        $user = reminderUser();
        $mail = new SubscriptionReminderMail($user, 'expiring');

        $html = $mail->render();

        expect($mail->envelope()->subject)->toBe('NeboTV: your subscription ends on '.$user->expiration_date->translatedFormat('j M Y'))
            ->and($html)->toContain($user->expiration_date->translatedFormat('j M Y'))
            ->toContain(route('me', ['token' => $user->playlist_token, 'lang' => 'en']))
            ->toContain('Open my page and ask to renew')
            ->not->toContain('playlist/'.$user->playlist_token); // not the playlist link itself
    });

    it('says the subscription has ended', function () {
        $user = reminderUser(['expiration_date' => now()->subDay()]);

        $mail = new SubscriptionReminderMail($user, 'expired');

        expect($mail->envelope()->subject)->toBe('NeboTV: your subscription has ended')
            ->and($mail->render())->toContain('The channels will not load until it is renewed.');
    });

    it('adds the contact line from RENEWAL_CONTACT', function () {
        config(['iptv.renewal_contact' => 'Call Nebojsa on 070 123 456.']);

        expect((new SubscriptionReminderMail(reminderUser(), 'expiring'))->render())->toContain('Call Nebojsa on 070 123 456.');
    });

    it('is written in the language chosen for the user', function () {
        $user = reminderUser(['locale' => 'mk']);
        app(SubscriptionReminderService::class)->run();

        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->locale === 'mk' && $mail->user->is($user));

        // ... and the text itself is Macedonian under that locale
        app()->setLocale('mk');
        expect((new SubscriptionReminderMail($user, 'expiring'))->envelope()->subject)->toContain('претплата');
    });

    it('has a plain-text part too', function () {
        $user = reminderUser();
        $text = view('emails.subscription-reminder-text', [
            'user' => $user, 'expired' => false, 'date' => 'x', 'pageUrl' => 'https://example.test/me/abc', 'contact' => '',
        ])->render();

        expect($text)->toContain('https://example.test/me/abc')->not->toContain('<');
    });
});

describe('the user form', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('saves the email and the language, both optional', function () {
        $this->post(route('admin.users.store'), [
            'username' => 'ana', 'password' => 'secret1', 'status' => 'active', 'role' => 'user',
            'max_connections' => 1, 'email' => 'ana@example.com', 'locale' => 'mk',
        ])->assertSessionHasNoErrors();

        expect(User::where('username', 'ana')->sole())->email->toBe('ana@example.com')->locale->toBe('mk');

        $this->post(route('admin.users.store'), [
            'username' => 'bob', 'password' => 'secret1', 'status' => 'active', 'role' => 'user', 'max_connections' => 1,
        ])->assertSessionHasNoErrors();
        expect(User::where('username', 'bob')->sole()->email)->toBeNull();
    });

    it('rejects a wrong address and an unknown language', function () {
        $this->post(route('admin.users.store'), [
            'username' => 'ana', 'password' => 'secret1', 'status' => 'active', 'role' => 'user',
            'max_connections' => 1, 'email' => 'not-an-email', 'locale' => 'xx',
        ])->assertSessionHasErrors(['email', 'locale']);
    });

    it('edits the email and shows it on the user page', function () {
        $user = reminderUser(['email' => null]);

        $this->put(route('admin.users.update', $user), [
            'username' => $user->username, 'status' => 'active', 'role' => 'user', 'max_connections' => 1, 'email' => 'new@example.com',
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.users.show', $user))->assertSee('new@example.com');
        $this->get(route('admin.users.edit', $user))->assertSee('value="new@example.com"', false)->assertSee('Language of emails');
    });
});

it('reports how many subscribers were emailed from the command', function () {
    reminderUser();

    $this->artisan('iptv:expiry-reminders')->expectsOutput('Reminders created: 1, emails sent to subscribers: 1')->assertSuccessful();
});
