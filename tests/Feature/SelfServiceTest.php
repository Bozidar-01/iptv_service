<?php

use App\Mail\StreamAlertsMail;
use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\StreamAlert;
use App\Models\User;

function selfServiceUser(array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['username' => 'anna', 'password' => bcrypt('s3cret-pass'), 'max_connections' => 2], $attributes));
    subscribedChannel($user, ['name' => 'Arena']);

    return $user;
}

it('shows a subscriber their status, expiry and playlist link', function () {
    $user = selfServiceUser(['expiration_date' => now()->addDays(60)]);

    $response = $this->get(route('me', ['token' => $user->playlist_token]))->assertOk();

    $response->assertSee('Hello, anna')
        ->assertSee('Active')
        ->assertSee('Valid until')
        ->assertSee(route('playlist', ['token' => $user->playlist_token]), false)
        ->assertSee('1 channel in')
        ->assertSee('0 of 2 in use')
        ->assertSee('noindex', false)
        ->assertDontSee('Ask to renew');
});

it('sets no cookie and never shows a password', function () {
    $user = selfServiceUser();

    $response = $this->get(route('me', ['token' => $user->playlist_token]))->assertOk();

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->getContent())->not->toContain('s3cret-pass')->not->toContain($user->password);
});

it('offers to ask for a renewal when the subscription ends within 30 days', function () {
    $user = selfServiceUser(['expiration_date' => now()->addDays(10)]);

    $this->get(route('me', ['token' => $user->playlist_token]))
        ->assertSee('Ask to renew')
        ->assertSee('ends soon');
});

it('still opens for an expired subscriber and lets them ask to renew', function () {
    $user = selfServiceUser(['expiration_date' => now()->subDays(3)]);

    $this->get(route('me', ['token' => $user->playlist_token]))
        ->assertOk()
        ->assertSee('Expired')
        ->assertSee('has ended')
        ->assertSee('Ask to renew');
});

it('tells a disabled user to contact whoever gave them access, without a renew button', function () {
    $user = selfServiceUser(['status' => 'disabled', 'expiration_date' => now()->addDays(5)]);

    $this->get(route('me', ['token' => $user->playlist_token]))
        ->assertOk()
        ->assertSee('Disabled')
        ->assertSee('switched off')
        ->assertDontSee('Ask to renew');
});

it('says "no end date" for a subscription that never ends', function () {
    $user = selfServiceUser(['expiration_date' => null]);

    $this->get(route('me', ['token' => $user->playlist_token]))->assertSee('No end date')->assertDontSee('Ask to renew');
});

it('is not found for unknown links, old links and admins', function () {
    $user = selfServiceUser();
    $admin = User::factory()->admin()->create();
    $old = $user->playlist_token;
    $user->regeneratePlaylistToken();

    $this->get(route('me', ['token' => 'nope']))->assertNotFound();
    $this->get(route('me', ['token' => $old]))->assertNotFound();
    $this->get(route('me', ['token' => $admin->playlist_token]))->assertNotFound();
    $this->get(route('me', ['token' => $user->playlist_token]))->assertOk();
});

it('escapes what it prints', function () {
    $user = selfServiceUser(['username' => '<script>alert(1)</script>']);

    $this->get(route('me', ['token' => $user->playlist_token]))->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});

describe('language', function () {
    it('follows ?lang= and the browser language, and falls back to English', function () {
        $user = selfServiceUser(['expiration_date' => now()->addDays(60)]);
        $url = route('me', ['token' => $user->playlist_token]);

        $this->get($url.'?lang=mk')->assertSee('Здраво, anna');
        $this->withHeader('Accept-Language', 'mk,en;q=0.5')->get($url)->assertSee('Здраво, anna');
        $this->withHeader('Accept-Language', 'de')->get($url)->assertSee('Hello, anna');
        $this->get($url.'?lang=xx')->assertSee('Hello, anna');
        $this->get($url.'?lang=mk')->assertSee('lang="mk"', false);
    });
});

describe('asking to renew', function () {
    it('creates an alert for the admin, once a day', function () {
        $user = selfServiceUser(['expiration_date' => now()->addDays(5)]);
        $url = route('me.renew', ['token' => $user->playlist_token]);

        $this->post($url)->assertRedirect(route('me', ['token' => $user->playlist_token, 'sent' => 'yes']));
        $this->post($url)->assertRedirect(route('me', ['token' => $user->playlist_token, 'sent' => 'again']));

        $alert = StreamAlert::where('type', 'renewal_requested')->sole();
        expect($alert->user_id)->toBe($user->id)->and($alert->message())->toBe('anna asks to renew the subscription');

        $this->travel(25)->hours();
        $this->post($url);
        expect(StreamAlert::where('type', 'renewal_requested')->count())->toBe(2);
    });

    it('confirms on the page after the redirect', function () {
        $user = selfServiceUser(['expiration_date' => now()->addDays(5)]);

        $this->followingRedirects()->post(route('me.renew', ['token' => $user->playlist_token]))->assertSee('Your request was sent');
        $this->followingRedirects()->post(route('me.renew', ['token' => $user->playlist_token]))->assertSee('already asked today');
    });

    it('keeps the language through the redirect', function () {
        $user = selfServiceUser(['expiration_date' => now()->addDays(5)]);

        $this->followingRedirects()->post(route('me.renew', ['token' => $user->playlist_token, 'lang' => 'mk']))->assertSee('Барањето е испратено');
    });

    it('is shown to the admin with a link to the user, and in the email digest', function () {
        $user = selfServiceUser(['expiration_date' => now()->addDays(5)]);
        $this->post(route('me.renew', ['token' => $user->playlist_token]));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.alerts.index'))
            ->assertSee('anna asks to renew the subscription')
            ->assertSee(route('admin.users.show', $user), false);

        $mail = new StreamAlertsMail(StreamAlert::all());
        expect(array_keys(array_filter($mail->groups(), fn ($group) => $group->isNotEmpty())))->toBe(['subscriptions']);
    });

    it('is not available for unknown links', function () {
        $this->post(route('me.renew', ['token' => 'nope']))->assertNotFound();
        expect(StreamAlert::count())->toBe(0);
    });
});

it('limits how often the page can be opened', function () {
    $user = selfServiceUser();
    $url = route('me', ['token' => $user->playlist_token]);

    foreach (range(1, 30) as $i) {
        $this->get($url)->assertOk();
    }
    $this->get($url)->assertStatus(429);
});

it('counts the devices that are watching now', function () {
    $user = selfServiceUser();
    ActiveSession::create(['user_id' => $user->id, 'channel_id' => Channel::first()->id, 'ip' => '10.0.0.5', 'user_agent' => 'X', 'started_at' => now(), 'last_activity_at' => now()]);

    $this->get(route('me', ['token' => $user->playlist_token]))->assertSee('1 of 2 in use');
});

it('is linked from the user page in the panel', function () {
    $user = selfServiceUser();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.users.show', $user))
        ->assertSee('Page for this user')
        ->assertSee(route('me', ['token' => $user->playlist_token]), false);
});
