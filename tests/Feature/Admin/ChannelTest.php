<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->category = Category::create(['name' => 'News']);
});

function channelPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'News 24',
        'stream_url' => 'https://upstream.test/news.m3u8',
        'category_id' => Category::first()->id,
        'is_active' => '1',
    ], $overrides);
}

it('creates a channel', function () {
    $this->post(route('admin.channels.store'), channelPayload())
        ->assertRedirect(route('admin.channels.index'));

    expect(Channel::where('name', 'News 24')->value('is_active'))->toBeTrue();
});

it('deactivates a channel when the checkbox is unchecked', function () {
    $channel = Channel::create(channelPayload(['is_active' => true]));

    // Unchecked checkbox: only the hidden input is sent
    $this->put(route('admin.channels.update', $channel), channelPayload(['is_active' => '0']))
        ->assertRedirect(route('admin.channels.index'));

    expect($channel->fresh()->is_active)->toBeFalse();
});

it('renders the hidden is_active fallback on the channel forms', function () {
    $channel = Channel::create(channelPayload());

    $this->get(route('admin.channels.create'))->assertOk()->assertSee('name="is_active" value="0"', false);
    $this->get(route('admin.channels.edit', $channel))->assertOk()->assertSee('name="is_active" value="0"', false);
});

it('renders every admin index page', function (string $route) {
    $this->get(route($route))->assertOk();
})->with([
    'admin.dashboard', 'admin.users.index', 'admin.channels.index',
    'admin.categories.index', 'admin.bouquets.index', 'admin.logs.index',
]);

it('does not register unimplemented resource routes', function (string $name) {
    expect(Route::has($name))->toBeFalse();
})->with([
    'admin.channels.show', 'admin.bouquets.show', 'admin.users.show',
    'admin.categories.show', 'admin.categories.create', 'admin.categories.edit',
]);
