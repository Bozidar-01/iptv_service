<?php

use App\Models\User;
use App\Services\AnnouncementService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->viewer = User::factory()->create();
    subscribedChannel($this->viewer);
});

it('lets the admin set a message that apps and viewers\' pages show', function () {
    $this->actingAs($this->admin)->post(route('admin.playlist-options.announcement'), ['text' => 'Maintenance tonight 02:00', 'until' => ''])
        ->assertSessionHas('success', 'Message saved. Apps show it at the next login; viewers see it on their page.');

    $this->get(route('xtream.api', ['username' => $this->viewer->username, 'password' => 'password']))
        ->assertJsonPath('user_info.message', 'Maintenance tonight 02:00');
    $this->get(route('me', ['token' => $this->viewer->playlist_token]))->assertOk()->assertSee('Maintenance tonight 02:00');
    $this->get(route('admin.playlist-options.index'))->assertSee('Shown now');
});

it('stops showing the message after its last day, and can be removed', function () {
    app(AnnouncementService::class)->save('New sports channels', now()->addDay()->toDateString());
    expect(app(AnnouncementService::class)->current())->toBe('New sports channels');

    $this->travel(2)->days();
    expect(app(AnnouncementService::class)->current())->toBeNull();
    $this->get(route('xtream.api', ['username' => $this->viewer->username, 'password' => 'password']))->assertJsonPath('user_info.message', '');

    $this->actingAs($this->admin)->post(route('admin.playlist-options.announcement'), ['text' => ''])->assertSessionHas('success', 'Message removed.');
    expect(app(AnnouncementService::class)->current())->toBeNull();
});

it('refuses a last day in the past and a message that is too long', function () {
    $this->actingAs($this->admin)->post(route('admin.playlist-options.announcement'), ['text' => 'x', 'until' => now()->subDay()->toDateString()])->assertSessionHasErrors('until');
    $this->post(route('admin.playlist-options.announcement'), ['text' => str_repeat('x', AnnouncementService::MAX_LENGTH + 1)])->assertSessionHasErrors('text');
});

it('is for admins only', function () {
    $this->actingAs(User::factory()->create(['role' => 'reseller']))->post(route('admin.playlist-options.announcement'), ['text' => 'x'])->assertForbidden();
});

it('shows QR codes of the playlist link and the viewer\'s page', function () {
    $page = $this->actingAs($this->admin)->get(route('admin.users.show', $this->viewer))->assertOk()
        ->assertSee('Show as QR codes')->assertSee('<svg', false);
    expect(substr_count($page->getContent(), '<svg xmlns'))->toBeGreaterThanOrEqual(2);

    $this->get(route('me', ['token' => $this->viewer->playlist_token]))->assertSee('Show as QR code')->assertSee('<svg xmlns', false);
});
