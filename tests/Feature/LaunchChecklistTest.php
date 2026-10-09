<?php

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\ChecklistTick;
use App\Models\Source;
use App\Models\User;
use App\Services\LaunchChecklistService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['password' => Hash::make('a-good-password')]);
});

/** Whether an item shows as done. */
function checklistDone(User $admin, string $key): bool
{
    foreach (app(LaunchChecklistService::class)->sections($admin) as $section) {
        foreach ($section['items'] as $item) {
            if ($item['key'] === $key) {
                return $item['done'];
            }
        }
    }

    throw new RuntimeException("No item $key");
}

it('shows the page to an admin and keeps resellers and visitors out', function () {
    $this->actingAs($this->admin)->get(route('admin.launch-checklist.index'))
        ->assertOk()->assertSee('Launch checklist')->assertSee('Two-factor sign-in is on for your account');

    $reseller = User::factory()->create(['role' => 'reseller']);
    $this->actingAs($reseller)->get(route('admin.launch-checklist.index'))->assertForbidden();
    $this->actingAs($reseller)->post(route('admin.launch-checklist.toggle', 'tv_tested'))->assertForbidden();

    auth()->logout();
    $this->get(route('admin.launch-checklist.index'))->assertRedirect(route('login'));
});

it('does not list the page in a reseller\'s menu but does in an admin\'s', function () {
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee(route('admin.launch-checklist.index'), false);

    $reseller = User::factory()->create(['role' => 'reseller']);
    $this->actingAs($reseller)->get(route('admin.dashboard'))->assertDontSee(route('admin.launch-checklist.index'), false);
});

describe('automatic items', function () {
    it('checks two-factor for the signed-in admin', function () {
        expect(checklistDone($this->admin, 'two_factor'))->toBeFalse();

        $this->admin->forceFill(['two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()])->save();

        expect(checklistDone($this->admin, 'two_factor'))->toBeTrue();
    });

    it('flags an admin who still has the sample password', function () {
        expect(checklistDone($this->admin, 'admin_password'))->toBeTrue();

        User::factory()->admin()->create(['password' => Hash::make('admin123')]);

        expect(checklistDone($this->admin, 'admin_password'))->toBeFalse();
    });

    it('checks production mode and https', function () {
        config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://x.test', 'session.secure' => false]);
        expect(checklistDone($this->admin, 'production'))->toBeFalse()
            ->and(checklistDone($this->admin, 'https'))->toBeFalse();

        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://x.test', 'session.secure' => true]);
        expect(checklistDone($this->admin, 'production'))->toBeTrue()
            ->and(checklistDone($this->admin, 'https'))->toBeTrue();
    });

    it('checks the backups', function () {
        config(['iptv.backup.copy_disk' => null]);
        expect(checklistDone($this->admin, 'backup_recent'))->toBeFalse()
            ->and(checklistDone($this->admin, 'backup_offsite'))->toBeFalse();

        Cache::forever('backup:last-success', now()->timestamp);
        config(['iptv.backup.copy_disk' => 's3']);

        expect(checklistDone($this->admin, 'backup_recent'))->toBeTrue()
            ->and(checklistDone($this->admin, 'backup_offsite'))->toBeTrue();
    });

    it('checks that alert emails can be sent', function () {
        config(['mail.default' => 'log', 'iptv.alert_mail_to' => ['me@example.com']]);
        expect(checklistDone($this->admin, 'mail'))->toBeFalse();

        config(['mail.default' => 'smtp', 'iptv.alert_mail_to' => []]);
        expect(checklistDone($this->admin, 'mail'))->toBeFalse();

        config(['mail.default' => 'smtp', 'iptv.alert_mail_to' => ['me@example.com']]);
        expect(checklistDone($this->admin, 'mail'))->toBeTrue();
    });

    it('checks the content: a source with channels, a bouquet with channels, a user', function () {
        expect(checklistDone($this->admin, 'source'))->toBeFalse()
            ->and(checklistDone($this->admin, 'bouquet'))->toBeFalse()
            ->and(checklistDone($this->admin, 'user'))->toBeFalse();

        $source = Source::create(['name' => 'S', 'url' => 'https://example.com/a.m3u']);
        $channel = Channel::create(['source_id' => $source->id, 'name' => 'One', 'stream_url' => 'https://example.com/1.ts']);
        Bouquet::create(['name' => 'B'])->channels()->attach($channel);
        User::factory()->create(['role' => 'user']);

        expect(checklistDone($this->admin, 'source'))->toBeTrue()
            ->and(checklistDone($this->admin, 'bouquet'))->toBeTrue()
            ->and(checklistDone($this->admin, 'user'))->toBeTrue();
    });
});

describe('manual items', function () {
    it('are ticked and unticked by an admin', function () {
        $this->actingAs($this->admin)->post(route('admin.launch-checklist.toggle', 'tv_tested'))
            ->assertRedirect(route('admin.launch-checklist.index').'#tv_tested');

        expect(ChecklistTick::where('item', 'tv_tested')->first()->user_id)->toBe($this->admin->id)
            ->and(checklistDone($this->admin, 'tv_tested'))->toBeTrue();

        $this->actingAs($this->admin)->post(route('admin.launch-checklist.toggle', 'tv_tested'));

        expect(ChecklistTick::count())->toBe(0)
            ->and(checklistDone($this->admin, 'tv_tested'))->toBeFalse();
    });

    it('can not be made up, and an automatic item can not be ticked', function () {
        $this->actingAs($this->admin)->post(route('admin.launch-checklist.toggle', 'nonsense'))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.launch-checklist.toggle', 'two_factor'))->assertNotFound();

        expect(ChecklistTick::count())->toBe(0);
    });
});

it('counts progress without the optional items', function () {
    $service = app(LaunchChecklistService::class);
    $before = $service->progress($this->admin);

    $total = collect($service->sections($this->admin))->pluck('items')->flatten(1)->where('optional', false)->count();
    expect($before['total'])->toBe($total);

    ChecklistTick::create(['item' => 'tv_tested', 'user_id' => $this->admin->id]);

    expect($service->progress($this->admin)['done'])->toBe($before['done'] + 1);
});
