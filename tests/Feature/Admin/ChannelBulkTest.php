<?php

use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->news = Category::create(['name' => 'News']);
    $this->sports = Category::create(['name' => 'Sports']);
    $this->channels = collect([
        ['BBC News', $this->news], ['CNN', $this->news], ['Sky Sports', $this->sports],
    ])->map(fn ($c) => Channel::create(['name' => $c[0], 'stream_url' => 'http://x.test/'.$c[0], 'category_id' => $c[1]->id, 'is_active' => true]));
});

it('filters the channel list', function () {
    $this->get(route('admin.channels.index', ['search' => 'sky']))
        ->assertOk()->assertSee('Sky Sports')->assertDontSee('CNN');

    $this->get(route('admin.channels.index', ['category' => $this->news->id]))
        ->assertSee('CNN')->assertDontSee('Sky Sports');
});

it('disables and enables selected channels', function () {
    $ids = $this->channels->take(2)->pluck('id')->all();

    $this->post(route('admin.channels.bulk'), ['action' => 'disable', 'scope' => 'selected', 'ids' => $ids])
        ->assertSessionHas('success', '2 channels disabled.');
    expect(Channel::where('is_active', false)->pluck('id')->all())->toBe($ids);

    $this->post(route('admin.channels.bulk'), ['action' => 'enable', 'scope' => 'selected', 'ids' => $ids]);
    expect(Channel::where('is_active', false)->count())->toBe(0);
});

it('applies an action to every channel matching the filters', function () {
    $this->post(route('admin.channels.bulk'), [
        'action' => 'disable', 'scope' => 'filtered', 'filters' => ['category' => $this->news->id],
    ])->assertSessionHas('success');

    expect(Channel::where('is_active', false)->pluck('name')->sort()->values()->all())->toBe(['BBC News', 'CNN']);
});

it('adds to and removes from a bouquet without duplicates', function () {
    $bouquet = Bouquet::create(['name' => 'Pack']);
    $bouquet->channels()->attach($this->channels[0]);

    $this->post(route('admin.channels.bulk'), ['action' => 'add_to_bouquet', 'scope' => 'filtered', 'filters' => [], 'bouquet_id' => $bouquet->id])
        ->assertSessionHas('success', '2 channels added to the bouquet.');
    expect($bouquet->channels()->count())->toBe(3);

    $this->post(route('admin.channels.bulk'), ['action' => 'remove_from_bouquet', 'scope' => 'selected', 'ids' => [$this->channels[2]->id], 'bouquet_id' => $bouquet->id]);
    expect($bouquet->channels()->pluck('name')->sort()->values()->all())->toBe(['BBC News', 'CNN']);
});

it('deletes selected channels', function () {
    $this->post(route('admin.channels.bulk'), ['action' => 'delete', 'scope' => 'selected', 'ids' => [$this->channels[0]->id]]);

    expect(Channel::count())->toBe(2);
});

it('requires a selection and a bouquet where needed', function () {
    $this->post(route('admin.channels.bulk'), ['action' => 'disable', 'scope' => 'selected'])
        ->assertSessionHasErrors('ids');
    $this->post(route('admin.channels.bulk'), ['action' => 'add_to_bouquet', 'scope' => 'filtered'])
        ->assertSessionHasErrors('bouquet_id');
});

it('adds whole categories to a bouquet from the bouquet form', function () {
    $this->post(route('admin.bouquets.store'), ['name' => 'News pack', 'categories' => [$this->news->id]])
        ->assertRedirect(route('admin.bouquets.index'));

    expect(Bouquet::where('name', 'News pack')->first()->channels()->pluck('name')->sort()->values()->all())
        ->toBe(['BBC News', 'CNN']);
});
