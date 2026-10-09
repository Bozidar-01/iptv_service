<?php

use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\User;
use App\Services\ChannelHideService;
use App\Services\StreamService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'boss']);

    $this->chA = Channel::create(['name' => 'Alpha', 'stream_url' => 'http://up.test/a.ts', 'is_active' => true]);
    $this->chB = Channel::create(['name' => 'Beta', 'stream_url' => 'http://up.test/b.ts', 'is_active' => true]);
    $this->chC = Channel::create(['name' => 'Gamma', 'stream_url' => 'http://up.test/c.ts', 'is_active' => true]);
    $this->chD = Channel::create(['name' => 'Delta (private)', 'stream_url' => 'http://up.test/d.ts', 'is_active' => true]);

    $this->shared = Bouquet::create(['name' => 'Shared']);
    $this->shared->channels()->attach([$this->chA->id, $this->chB->id, $this->chC->id]);
    $this->extra = Bouquet::create(['name' => 'Extra']);
    $this->extra->channels()->attach($this->chD);

    $this->r1 = User::factory()->create(['username' => 'resA', 'role' => 'reseller']);
    $this->r1->bouquets()->attach($this->shared);
    $this->r2 = User::factory()->create(['username' => 'resB', 'role' => 'reseller']);
    $this->r2->bouquets()->attach($this->shared);

    $this->c1 = User::factory()->create(['username' => 'cust1', 'owner_id' => $this->r1->id]);
    $this->c2 = User::factory()->create(['username' => 'cust2', 'owner_id' => $this->r1->id]);
    $this->c3 = User::factory()->create(['username' => 'cust3', 'owner_id' => $this->r2->id]);
    $this->own = User::factory()->create(['username' => 'adminuser', 'owner_id' => null]);
    foreach ([$this->c1, $this->c2, $this->c3, $this->own] as $u) {
        $u->bouquets()->attach($this->shared);
    }
});

/** The names of the channels a user gets. */
function got(User $user): array
{
    return Channel::availableTo($user)->orderBy('name')->pluck('name')->all();
}

describe('what a user gets', function () {
    it('drops a channel hidden for that user only', function () {
        $this->c1->hiddenChannels()->attach($this->chB);

        expect(got($this->c1))->toBe(['Alpha', 'Gamma'])
            ->and(got($this->c2))->toBe(['Alpha', 'Beta', 'Gamma']);
    });

    it('drops a channel hidden for a reseller\'s users, for their customers only', function () {
        DB::table('owner_hidden_channels')->insert(['owner_id' => $this->r1->id, 'channel_id' => $this->chA->id]);

        expect(got($this->c1))->toBe(['Beta', 'Gamma'])
            ->and(got($this->c2))->toBe(['Beta', 'Gamma'])
            ->and(got($this->c3))->toBe(['Alpha', 'Beta', 'Gamma'])
            ->and(got($this->own))->toBe(['Alpha', 'Beta', 'Gamma']);
    });

    it('drops a channel the admins hid for their own users, and only for those', function () {
        DB::table('owner_hidden_channels')->insert(['owner_id' => null, 'channel_id' => $this->chC->id]);

        expect(got($this->own))->toBe(['Alpha', 'Beta'])
            ->and(got($this->c1))->toBe(['Alpha', 'Beta', 'Gamma']);
    });

    it('is followed by the playlist, the Xtream list, playback and the channel count', function () {
        $this->c1->hiddenChannels()->attach($this->chB);

        $playlist = $this->get('/m3u?'.http_build_query(['username' => 'cust1', 'password' => 'password']))->streamedContent();
        expect($playlist)->toContain('Alpha')->toContain('Gamma')->not->toContain('Beta');

        $xtream = $this->getJson('/player_api.php?'.http_build_query(['username' => 'cust1', 'password' => 'password', 'action' => 'get_live_streams']))
            ->assertOk()->json();
        expect(collect($xtream)->pluck('name')->sort()->values()->all())->toBe(['Alpha', 'Gamma']);

        $streams = app(StreamService::class);
        expect($streams->isAllowed($this->c1, $this->chB))->toBeFalse()
            ->and($streams->isAllowed($this->c1, $this->chA))->toBeTrue()
            ->and($streams->isAllowed($this->c2, $this->chB))->toBeTrue();

        $this->get('/live/cust1/password/'.$this->chB->id.'.ts')->assertForbidden();

        expect(app(UserService::class)->channelCount($this->c1))->toBe(2);
    });
});

describe('the pages', function () {
    it('shows a reseller only the channels of the bouquets they hold', function () {
        $this->actingAs($this->r1)->get(route('admin.hide-channels.index'))
            ->assertOk()->assertSee('Alpha')->assertSee('Gamma')->assertDontSee('Delta (private)')
            ->assertSee('0 of 3 channels hidden');

        $this->actingAs($this->admin)->get(route('admin.hide-channels.index'))
            ->assertOk()->assertSee('Delta (private)')->assertSee('0 of 4 channels hidden');
    });

    it('keeps visitors and other roles out, and lists it in both menus', function () {
        $this->get(route('admin.hide-channels.index'))->assertRedirect(route('login'));
        $this->actingAs($this->c1)->get(route('admin.hide-channels.index'))->assertRedirect(route('login'));

        $this->actingAs($this->r1)->get(route('admin.dashboard'))->assertSee(route('admin.hide-channels.index'), false);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee(route('admin.hide-channels.index'), false);
    });

    it('filters by search, bouquet and state', function () {
        DB::table('owner_hidden_channels')->insert(['owner_id' => $this->r1->id, 'channel_id' => $this->chA->id]);

        $this->actingAs($this->r1)->get(route('admin.hide-channels.index', ['state' => 'hidden']))
            ->assertSee('Alpha')->assertDontSee('Gamma');
        $this->actingAs($this->r1)->get(route('admin.hide-channels.index', ['state' => 'visible']))
            ->assertDontSee('Alpha')->assertSee('Gamma');
        $this->actingAs($this->r1)->get(route('admin.hide-channels.index', ['search' => 'Bet']))
            ->assertSee('Beta')->assertDontSee('Gamma');
        // A bouquet the reseller does not hold is ignored, not an error
        $this->actingAs($this->r1)->get(route('admin.hide-channels.index', ['bouquet' => $this->extra->id]))->assertOk()->assertSee('Gamma');
    });

    it('shows a user\'s channels, with what is hidden and why', function () {
        $this->c1->hiddenChannels()->attach($this->chB);
        DB::table('owner_hidden_channels')->insert(['owner_id' => $this->r1->id, 'channel_id' => $this->chA->id]);

        $this->actingAs($this->r1)->get(route('admin.users.channels', $this->c1))
            ->assertOk()->assertSee('Channels of cust1')
            ->assertSee('Hidden for cust1')->assertSee('Hidden for all my users')
            ->assertSee('2 of 3 channels hidden');

        // An admin looking at the reseller's customer sees whose list hides it
        $this->actingAs($this->admin)->get(route('admin.users.channels', $this->c1))
            ->assertSee('Hidden for all of resA');
    });

    it('does not show a reseller the bouquets the admin added to their customer', function () {
        $this->c1->bouquets()->attach($this->extra);

        $this->actingAs($this->r1)->get(route('admin.users.channels', $this->c1))->assertDontSee('Delta (private)');
        $this->actingAs($this->admin)->get(route('admin.users.channels', $this->c1))->assertSee('Delta (private)');
    });

    it('keeps a reseller out of other resellers\' users', function () {
        $this->actingAs($this->r1)->get(route('admin.users.channels', $this->c3))->assertNotFound();
        $this->actingAs($this->r1)->post(route('admin.users.channels.bulk', $this->c3), ['action' => 'hide', 'scope' => 'selected', 'ids' => [$this->chA->id]])->assertNotFound();

        expect(DB::table('user_hidden_channels')->count())->toBe(0);
    });
});

describe('hiding and showing', function () {
    it('hides selected channels for one user', function () {
        $this->actingAs($this->r1)->post(route('admin.users.channels.bulk', $this->c1), [
            'action' => 'hide', 'scope' => 'selected', 'ids' => [$this->chA->id, $this->chB->id],
        ])->assertRedirect()->assertSessionHas('success', '2 channels hidden.');

        expect(got($this->c1))->toBe(['Gamma'])->and(got($this->c2))->toHaveCount(3);
    });

    it('shows them again, and hiding twice does not duplicate', function () {
        $post = fn (string $action) => $this->actingAs($this->r1)->post(route('admin.users.channels.bulk', $this->c1), [
            'action' => $action, 'scope' => 'selected', 'ids' => [$this->chA->id],
        ]);

        $post('hide');
        $post('hide')->assertSessionHas('success', '0 channels hidden.');
        expect(DB::table('user_hidden_channels')->count())->toBe(1);

        $post('show')->assertSessionHas('success', '1 channel shown again.');
        expect(DB::table('user_hidden_channels')->count())->toBe(0)->and(got($this->c1))->toHaveCount(3);
    });

    it('hides everything matching the filters', function () {
        $this->actingAs($this->r1)->post(route('admin.hide-channels.bulk'), [
            'action' => 'hide', 'scope' => 'filtered', 'filters' => ['search' => 'a'],
        ])->assertSessionHas('success', '3 channels hidden.');

        expect(got($this->c1))->toBe([])->and(got($this->c3))->toHaveCount(3);
    });

    it('hides for all of a reseller\'s users, and for the admins\' own users when an admin does it', function () {
        $this->actingAs($this->r1)->post(route('admin.hide-channels.bulk'), ['action' => 'hide', 'scope' => 'selected', 'ids' => [$this->chA->id]]);
        $this->actingAs($this->admin)->post(route('admin.hide-channels.bulk'), ['action' => 'hide', 'scope' => 'selected', 'ids' => [$this->chB->id]]);

        expect(got($this->c1))->toBe(['Beta', 'Gamma'])
            ->and(got($this->own))->toBe(['Alpha', 'Gamma'])
            ->and(got($this->c3))->toHaveCount(3);

        // The lists are separate: showing the reseller's channel leaves the admins' list alone
        $this->actingAs($this->r1)->post(route('admin.hide-channels.bulk'), ['action' => 'show', 'scope' => 'selected', 'ids' => [$this->chA->id, $this->chB->id]]);
        expect(got($this->c1))->toHaveCount(3)->and(got($this->own))->toBe(['Alpha', 'Gamma']);
    });

    it('ignores channels the reseller does not offer', function () {
        $this->actingAs($this->r1)->post(route('admin.hide-channels.bulk'), ['action' => 'hide', 'scope' => 'selected', 'ids' => [$this->chD->id]])
            ->assertSessionHas('success', '0 channels hidden.');
        $this->c1->bouquets()->attach($this->extra);
        $this->actingAs($this->r1)->post(route('admin.users.channels.bulk', $this->c1), ['action' => 'hide', 'scope' => 'selected', 'ids' => [$this->chD->id]]);

        expect(DB::table('owner_hidden_channels')->count())->toBe(0)->and(DB::table('user_hidden_channels')->count())->toBe(0);
    });

    it('needs a choice', function () {
        $this->actingAs($this->r1)->post(route('admin.hide-channels.bulk'), ['action' => 'hide', 'scope' => 'selected'])->assertSessionHasErrors('ids');
        $this->actingAs($this->r1)->post(route('admin.hide-channels.bulk'), ['action' => 'nonsense', 'scope' => 'selected', 'ids' => [1]])->assertSessionHasErrors('action');
    });

    it('is removed with the user and with the channel', function () {
        $this->c1->hiddenChannels()->attach($this->chA);
        DB::table('owner_hidden_channels')->insert(['owner_id' => $this->r1->id, 'channel_id' => $this->chB->id]);

        $this->chA->delete();
        $this->r1->delete();

        expect(DB::table('user_hidden_channels')->count())->toBe(0)->and(DB::table('owner_hidden_channels')->count())->toBe(0);
    });
});

describe('by category', function () {
    beforeEach(function () {
        $this->news = Category::create(['name' => 'News']);
        $this->chA->update(['category_id' => $this->news->id]);
        $this->chB->update(['category_id' => $this->news->id]);
    });

    it('lists the categories with how many channels are hidden', function () {
        DB::table('owner_hidden_channels')->insert(['owner_id' => $this->r1->id, 'channel_id' => $this->chA->id]);

        $stats = app(ChannelHideService::class)->categoryStats(app(ChannelHideService::class)->channelsIn($this->r1->bouquets()), $this->r1->id);

        expect($stats)->toBe([
            ['id' => $this->news->id, 'name' => 'News', 'total' => 2, 'hidden' => 1],
            ['id' => null, 'name' => null, 'total' => 1, 'hidden' => 0],
        ]);

        $this->actingAs($this->r1)->get(route('admin.hide-channels.index'))->assertSee('By category')->assertSee('News')->assertSee('1 hidden');
    });

    it('hides and shows a whole category, and the uncategorized ones', function () {
        $post = fn (string $action, $category) => $this->actingAs($this->r1)->post(route('admin.hide-channels.bulk'), ['action' => $action, 'scope' => 'filtered', 'filters' => ['category' => $category]]);

        $post('hide', $this->news->id)->assertSessionHas('success', '2 channels hidden.');
        expect(got($this->c1))->toBe(['Gamma']);

        $post('hide', 'none')->assertSessionHas('success', '1 channel hidden.');
        expect(got($this->c1))->toBe([]);

        $post('show', $this->news->id)->assertSessionHas('success', '2 channels shown again.');
        expect(got($this->c1))->toBe(['Alpha', 'Beta']);
    });

    it('works on a user\'s page too', function () {
        $this->actingAs($this->r1)->post(route('admin.users.channels.bulk', $this->c1), ['action' => 'hide', 'scope' => 'filtered', 'filters' => ['category' => $this->news->id]]);

        expect(got($this->c1))->toBe(['Gamma'])->and(got($this->c2))->toHaveCount(3);
        $this->actingAs($this->r1)->get(route('admin.users.channels', $this->c1))->assertSee('By category')->assertSee('2 hidden');
    });
});
