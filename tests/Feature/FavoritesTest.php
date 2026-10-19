<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\User;
use App\Services\FavoriteService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'maria', 'password' => bcrypt('secret'), 'expiration_date' => null]);
    $this->rai = subscribedChannel($this->user, ['name' => 'Rai 1']);
    $this->mega = subscribedChannel($this->user, ['name' => 'Mega TV']);
    $this->zed = subscribedChannel($this->user, ['name' => 'Zed News']);
    $this->mega->update(['category_id' => Category::create(['name' => 'Mega'])->id]);
    $this->foreign = Channel::create(['name' => 'Not Theirs', 'stream_url' => 'http://x.test/n', 'is_active' => true]);
    $this->url = fn () => route('me.favourite', ['token' => $this->user->playlist_token]);
    $this->act = fn (string $do, Channel $c) => $this->post(($this->url)(), ['do' => $do, 'channel' => $c->id]);
});

function favNames(User $user): array
{
    return app(FavoriteService::class)->channels($user)->pluck('name')->all();
}

describe('the list', function () {
    it('adds, orders and removes favourites', function () {
        ($this->act)('add', $this->zed)->assertRedirect();
        ($this->act)('add', $this->rai);
        ($this->act)('add', $this->mega);
        expect(favNames($this->user))->toBe(['Zed News', 'Rai 1', 'Mega TV']);

        ($this->act)('up', $this->mega);
        expect(favNames($this->user))->toBe(['Zed News', 'Mega TV', 'Rai 1']);
        ($this->act)('down', $this->zed);
        expect(favNames($this->user))->toBe(['Mega TV', 'Zed News', 'Rai 1']);
        // The ends stay where they are
        ($this->act)('up', $this->mega);
        ($this->act)('down', $this->rai);
        expect(favNames($this->user))->toBe(['Mega TV', 'Zed News', 'Rai 1']);

        ($this->act)('remove', $this->zed);
        expect(favNames($this->user))->toBe(['Mega TV', 'Rai 1']);
    });

    it('adds a channel once, and only one the user can watch', function () {
        ($this->act)('add', $this->rai);
        ($this->act)('add', $this->rai);
        ($this->act)('add', $this->foreign);

        expect(DB::table('user_favorites')->count())->toBe(1);
    });

    it('stops at the limit', function () {
        foreach (range(1, FavoriteService::MAX) as $i) {
            subscribedChannel($this->user, ['name' => "Ch $i"]);
        }
        $service = app(FavoriteService::class);
        Channel::where('name', 'like', 'Ch %')->get()->each(fn ($c) => $service->add($this->user, $c->id));

        expect(DB::table('user_favorites')->count())->toBe(FavoriteService::MAX)->and($service->add($this->user, $this->rai->id))->toBeFalse();
    });

    it('lets a channel drop out while it is switched off and brings it back', function () {
        ($this->act)('add', $this->rai);
        $this->rai->update(['is_active' => false]);
        expect(favNames($this->user))->toBe([]);

        $this->rai->update(['is_active' => true]);
        expect(favNames($this->user))->toBe(['Rai 1']);
    });

    it('is removed with the user or the channel', function () {
        ($this->act)('add', $this->rai);
        ($this->act)('add', $this->mega);
        $this->rai->delete();
        expect(DB::table('user_favorites')->count())->toBe(1);

        $this->user->delete();
        expect(DB::table('user_favorites')->count())->toBe(0);
    });
});

describe('the subscriber page', function () {
    it('shows favourites, searches channels and adds from the results', function () {
        ($this->act)('add', $this->rai);
        $page = route('me', ['token' => $this->user->playlist_token]);

        $this->get($page)->assertOk()->assertSee('My favourites')->assertSee('Rai 1');
        $this->get($page.'?q=meg')->assertSee('Mega TV')->assertDontSee('Zed News');
        // Already a favourite, or not theirs: not offered
        $this->get($page.'?q=rai')->assertDontSee('Add</button>', false)->assertSee('No channel found.');
        $this->get($page.'?q=not')->assertSee('No channel found.');
        $this->get($page.'?q=m')->assertSee('Type at least 2 letters.');
    });

    it('is hidden for an expired user and an unknown link', function () {
        $this->user->update(['expiration_date' => now()->subDay()]);
        $this->get(route('me', ['token' => $this->user->playlist_token]))->assertOk()->assertDontSee('My favourites');

        $this->post(route('me.favourite', ['token' => 'nope']), ['do' => 'add', 'channel' => $this->rai->id])->assertNotFound();
        $this->post(($this->url)(), ['do' => 'nonsense', 'channel' => 1])->assertStatus(422);
    });
});

describe('in the apps', function () {
    it('puts favourites first in the playlist, in their own category', function () {
        ($this->act)('add', $this->zed);
        ($this->act)('add', $this->rai);

        $m3u = $this->get('/m3u?'.http_build_query(['username' => 'maria', 'password' => 'secret']))->streamedContent();
        preg_match_all('/group-title="([^"]*)",(.*)/', $m3u, $m, PREG_SET_ORDER);
        $entries = array_map(fn ($x) => [$x[1], $x[2]], $m);

        expect(array_slice($entries, 0, 2))->toBe([['Favourites', 'Zed News'], ['Favourites', 'Rai 1']])
            // and the channels are still in the list below
            ->and(count($entries))->toBe(5)->and(array_column($entries, 1))->toContain('Mega TV');
    });

    it('has no Favourites category until there is one', function () {
        $m3u = $this->get('/m3u?'.http_build_query(['username' => 'maria', 'password' => 'secret']))->streamedContent();
        expect($m3u)->not->toContain('Favourites');

        $api = fn (array $p = []) => $this->getJson('/player_api.php?'.http_build_query(['username' => 'maria', 'password' => 'secret'] + $p));
        expect(collect($api(['action' => 'get_live_categories'])->json())->pluck('category_name')->all())->not->toContain('Favourites');
    });

    it('lists the Favourites category first in the Xtream API, with the favourites in order', function () {
        ($this->act)('add', $this->zed);
        ($this->act)('add', $this->rai);
        $api = fn (array $p) => $this->getJson('/player_api.php?'.http_build_query(['username' => 'maria', 'password' => 'secret'] + $p));

        $categories = $api(['action' => 'get_live_categories'])->json();
        expect($categories[0])->toBe(['category_id' => '-1', 'category_name' => 'Favourites', 'parent_id' => 0]);

        $only = $api(['action' => 'get_live_streams', 'category_id' => '-1'])->json();
        expect(array_column($only, 'name'))->toBe(['Zed News', 'Rai 1'])->and(array_unique(array_column($only, 'category_id')))->toBe(['-1']);

        // A real category shows only its own channels; the full list has the favourites first, then everything
        expect(array_column($api(['action' => 'get_live_streams', 'category_id' => (string) $this->mega->category_id])->json(), 'name'))->not->toContain('Zed News');
        $all = $api(['action' => 'get_live_streams'])->json();
        expect(array_column(array_slice($all, 0, 2), 'name'))->toBe(['Zed News', 'Rai 1'])->and(count($all))->toBe(5);
    });
});
