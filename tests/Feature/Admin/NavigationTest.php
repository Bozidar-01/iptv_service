<?php

use App\Models\User;
use App\Support\AdminNav;

function menuLabels(User $user): array
{
    return array_map(fn ($item) => $item['section'] ?? $item['label'], AdminNav::sidebar($user));
}

it('groups the menu by what you are doing', function () {
    expect(menuLabels(User::factory()->admin()->create()))->toBe([
        'Overview', 'Dashboard', 'Alerts', 'Watching now',
        'Channels', 'Channels', 'Bouquets', 'Categories', 'Sources', 'TV guide',
        'Users', 'Users', 'Hide channels', 'Activity',
        'Money', 'Money',
        'Reports', 'Statistics',
        'Maintenance', 'Backups', 'Audit log', 'Launch checklist',
    ]);
});

it('shows resellers only their part, without empty sections', function () {
    expect(menuLabels(User::factory()->create(['role' => 'reseller'])))->toBe([
        'Overview', 'Dashboard', 'Alerts', 'Watching now',
        'Users', 'Users', 'Hide channels', 'Activity',
        'Money', 'Money',
    ]);
});

it('shows the tabs of a section and marks the page and its menu link', function () {
    $admin = User::factory()->admin()->create();

    $page = $this->actingAs($admin)->get(route('admin.cleanup.index'))->assertOk();
    $page->assertSee('class="tabs"', false)
        ->assertSee('All channels')->assertSee('Duplicates')->assertSee('Playlist options')
        ->assertSee('href="'.route('admin.cleanup.index').'"  aria-current="page"', false)
        // The Channels link in the menu is active on all its tabs
        ->assertSee('href="'.route('admin.channels.index').'" class="nav-link active"', false);

    // Pages outside a section have no tabs
    $this->get(route('admin.dashboard'))->assertDontSee('class="tabs"', false);
});

it('leaves tabs a reseller can not open out of their Money section', function () {
    $this->actingAs(User::factory()->create(['role' => 'reseller']))->get(route('admin.payments.index'))->assertOk()
        ->assertSee('Statements')->assertSee('Credit history')->assertDontSee(route('admin.sales.index'), false);
});

it('keeps Security in the menu under your name', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
        ->assertSee('aria-label="Security"', false)->assertSee(route('admin.security.index'), false);
});
