<?php

use App\Models\Bouquet;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows validation errors on the bouquet create form', function () {
    Bouquet::create(['name' => 'Taken']);

    $this->from(route('admin.bouquets.create'))
        ->followingRedirects()
        ->post(route('admin.bouquets.store'), ['name' => 'Taken'])
        ->assertSee('The name has already been taken.');
});

it('shows validation errors on the bouquet edit form', function () {
    Bouquet::create(['name' => 'Taken']);
    $bouquet = Bouquet::create(['name' => 'Mine']);

    $this->from(route('admin.bouquets.edit', $bouquet))
        ->followingRedirects()
        ->put(route('admin.bouquets.update', $bouquet), ['name' => 'Taken'])
        ->assertSee('The name has already been taken.');
});

it('shows validation errors on the categories page', function () {
    $this->from(route('admin.categories.index'))
        ->followingRedirects()
        ->post(route('admin.categories.store'), ['name' => ''])
        ->assertSee('The name field is required.');
});
