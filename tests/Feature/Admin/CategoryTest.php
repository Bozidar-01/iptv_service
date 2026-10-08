<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('lists categories with the number of channels in each', function () {
    $news = Category::create(['name' => 'News']);
    Category::create(['name' => 'Sports']);
    Channel::create(['name' => 'N1', 'stream_url' => 'http://s.test/1.ts', 'category_id' => $news->id]);
    Channel::create(['name' => 'N2', 'stream_url' => 'http://s.test/2.ts', 'category_id' => $news->id]);

    $this->get(route('admin.categories.index'))
        ->assertOk()
        ->assertSeeInOrder(['News', 'Sports']);

    expect($news->channels()->count())->toBe(2);
});

it('creates a category', function () {
    $this->post(route('admin.categories.store'), ['name' => 'Kids'])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');

    expect(Category::where('name', 'Kids')->exists())->toBeTrue();
});

it('needs a name that is not taken', function () {
    Category::create(['name' => 'News']);

    $this->post(route('admin.categories.store'), ['name' => ''])->assertSessionHasErrors('name');
    $this->post(route('admin.categories.store'), ['name' => 'News'])->assertSessionHasErrors('name');
    $this->post(route('admin.categories.store'), ['name' => str_repeat('x', 256)])->assertSessionHasErrors('name');

    expect(Category::count())->toBe(1);
});

it('renames a category, and may keep its own name', function () {
    $news = Category::create(['name' => 'News']);
    $other = Category::create(['name' => 'Sports']);

    $this->put(route('admin.categories.update', $news), ['name' => 'News'])->assertSessionHasNoErrors();
    $this->put(route('admin.categories.update', $news), ['name' => 'Sports'])->assertSessionHasErrors('name');
    $this->put(route('admin.categories.update', $news), ['name' => 'World news'])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');

    expect($news->fresh()->name)->toBe('World news')->and($other->fresh()->name)->toBe('Sports');
});

it('deletes a category and keeps its channels', function () {
    $news = Category::create(['name' => 'News']);
    $channel = Channel::create(['name' => 'N1', 'stream_url' => 'http://s.test/1.ts', 'category_id' => $news->id]);

    $this->delete(route('admin.categories.destroy', $news))
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');

    expect(Category::count())->toBe(0)
        ->and($channel->fresh())->not->toBeNull()
        ->and($channel->fresh()->category_id)->toBeNull();
});

it('is for admins only', function () {
    auth()->logout();
    $this->post(route('admin.categories.store'), ['name' => 'Kids'])->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->post(route('admin.categories.store'), ['name' => 'Kids'])->assertRedirect();
    expect(Category::count())->toBe(0);
});
