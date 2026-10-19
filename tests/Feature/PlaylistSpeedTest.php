<?php

use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\DeniedPlay;
use App\Models\User;
use App\Services\ChannelNumberService;
use App\Services\ListCacheService;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function speedPlaylist($test, User $user, array $headers = []): string
{
    return $test->withHeaders($headers)->get(route('playlist', ['token' => $user->playlist_token]))->streamedContent();
}

function speedPlayLinks(string $playlist): array
{
    preg_match_all('#http://[^\s]+/play/\d+/[^\s]+#', $playlist, $m);

    return $m[0];
}

describe('devices', function () {
    it('gives every playlist download its own device key in the play links', function () {
        $user = User::factory()->create();
        subscribedChannel($user);

        $first = speedPlayLinks(speedPlaylist($this, $user))[0];
        $this->travel(2)->seconds();
        $second = speedPlayLinks(speedPlaylist($this, $user))[0];

        expect($first)->not->toBe($second)
            ->and(TokenService::parsePlayToken(basename($first)))->toMatchArray([1 => TokenService::parsePlayToken(basename($first))[1]])
            ->and(TokenService::parsePlayToken('abc'))->toBe(['abc', null])
            ->and(TokenService::parsePlayToken('abc_BAD!.ts'))->toBe(['abc', null]);
    });

    it('counts two identical TVs behind one router as two devices', function () {
        $user = User::factory()->create(['max_connections' => 1]);
        subscribedChannel($user);
        $tv1 = speedPlayLinks(speedPlaylist($this, $user))[0];
        $this->travel(5)->seconds();
        $tv2 = speedPlayLinks(speedPlaylist($this, $user))[0];
        $this->travel(5)->seconds();

        // TV 1 plays after TV 2 loaded its playlist: TV 2's key is not its successor
        $this->get($tv1)->assertRedirect();
        $this->get($tv2)->assertForbidden();

        expect(DeniedPlay::where('reason', DeniedPlay::LIMIT)->count())->toBe(1);
    });

    it('lets a TV that loaded its playlist again take over its own slot', function () {
        $user = User::factory()->create(['max_connections' => 1]);
        subscribedChannel($user);
        $old = speedPlayLinks(speedPlaylist($this, $user))[0];
        $this->get($old)->assertRedirect();

        $this->travel(5)->seconds();
        $new = speedPlayLinks(speedPlaylist($this, $user))[0];
        $this->travel(5)->seconds();

        $this->get($new)->assertRedirect();
        expect(ActiveSession::where('user_id', $user->id)->count())->toBe(1)
            ->and(ActiveSession::where('user_id', $user->id)->value('device_key'))->toBe(TokenService::parsePlayToken(basename($new))[1]);
    });

    it('still tells Xtream apps apart by address and app', function () {
        $user = User::factory()->create(['max_connections' => 1]);
        $channel = subscribedChannel($user);

        $this->get(route('xtream.live', ['username' => $user->username, 'password' => 'password', 'stream' => $channel->id.'.ts']))->assertRedirect();
        $this->withHeaders(['User-Agent' => 'Other/1'])->get(route('xtream.live', ['username' => $user->username, 'password' => 'password', 'stream' => $channel->id.'.ts']))->assertForbidden();
    });
});

describe('channel numbers', function () {
    it('gives new channels the next number and sends it to apps', function () {
        $user = User::factory()->create();
        $a = subscribedChannel($user, ['name' => 'A']);
        $b = subscribedChannel($user, ['name' => 'B']);

        expect($b->number)->toBe($a->number + 1)
            ->and(speedPlaylist($this, $user))->toContain('tvg-chno="'.$b->number.'"');

        $streams = $this->get(route('xtream.api', ['username' => $user->username, 'password' => 'password', 'action' => 'get_live_streams']))->streamedContent();
        expect(collect(json_decode($streams, true))->pluck('num', 'stream_id')->all())->toBe([$a->id => $a->number, $b->id => $b->number]);
    });

    it('numbers everything again in playlist order, and numbers imported channels', function () {
        $first = Channel::create(['name' => 'Zed', 'stream_url' => 'http://x.test/1', 'number' => 50]);
        $second = Channel::create(['name' => 'Alpha', 'stream_url' => 'http://x.test/2', 'number' => 7]);
        DB::table('channels')->insert(['name' => 'Imported', 'stream_url' => 'http://x.test/3', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        expect(app(ChannelNumberService::class)->fillMissing())->toBe(1)
            ->and(Channel::where('name', 'Imported')->value('number'))->toBe(51);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.playlist-options.renumber'))->assertSessionHas('success', '3 channels numbered.');
        expect(Channel::orderBy('number')->pluck('name')->all())->toBe(['Alpha', 'Imported', 'Zed']);
    });

    it('keeps the number when the edit form leaves it empty', function () {
        $this->actingAs(User::factory()->admin()->create());
        $channel = Channel::create(['name' => 'X', 'stream_url' => 'http://x.test/1', 'number' => 42]);

        $this->put(route('admin.channels.update', $channel), ['name' => 'X', 'stream_url' => 'http://x.test/1', 'is_active' => '1', 'number' => '']);
        expect($channel->fresh()->number)->toBe(42);

        $this->put(route('admin.channels.update', $channel), ['name' => 'X', 'stream_url' => 'http://x.test/1', 'is_active' => '1', 'number' => '101']);
        expect($channel->fresh()->number)->toBe(101);
    });
});

describe('ready lists', function () {
    it('shares one list between viewers who see the same channels and makes it again after a change', function () {
        $bouquetUser = User::factory()->create();
        $channel = subscribedChannel($bouquetUser, ['name' => 'Before']);
        $other = User::factory()->create();
        $other->bouquets()->attach($bouquetUser->bouquets()->pluck('bouquets.id'));

        $first = speedPlaylist($this, $bouquetUser);
        // A change the check can't see stays out until the list is made again...
        DB::table('channels')->where('id', $channel->id)->update(['name' => 'Silent']);
        expect(speedPlaylist($this, $other))->toContain(',Before')->not->toContain($bouquetUser->accessTokens()->value('token'));

        // ...which a normal change does (the check works in whole seconds and is remembered for a few)
        $this->travel(20)->seconds();
        $channel->update(['name' => 'After']);
        expect(speedPlaylist($this, $other))->toContain(',After')
            ->and($first)->toContain(',Before');
    });

    it('gives each viewer their own token in the shared list', function () {
        $a = User::factory()->create();
        subscribedChannel($a);
        $b = User::factory()->create();
        $b->bouquets()->attach($a->bouquets()->pluck('bouquets.id'));

        $tokenA = TokenService::parsePlayToken(basename(speedPlayLinks(speedPlaylist($this, $a))[0]))[0];
        $tokenB = TokenService::parsePlayToken(basename(speedPlayLinks(speedPlaylist($this, $b))[0]))[0];

        expect($tokenA)->toBe($a->accessTokens()->value('token'))->and($tokenB)->toBe($b->accessTokens()->value('token'));
    });

    it('makes lists again after bump()', function () {
        $user = User::factory()->create();
        $channel = subscribedChannel($user, ['name' => 'Old name']);
        speedPlaylist($this, $user);
        DB::table('channels')->where('id', $channel->id)->update(['name' => 'New name']);

        ListCacheService::bump();

        expect(speedPlaylist($this, $user))->toContain(',New name');
    });

    it('compresses playlists, channel lists and guides for apps that accept it', function () {
        $user = User::factory()->create();
        subscribedChannel($user, ['name' => 'Packed']);

        $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get(route('playlist', ['token' => $user->playlist_token]));
        expect($response->headers->get('Content-Encoding'))->toBe('gzip')
            ->and(gzdecode($response->streamedContent()))->toContain(',Packed');

        $api = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get(route('xtream.api', ['username' => $user->username, 'password' => 'password', 'action' => 'get_live_streams']));
        expect(json_decode(gzdecode($api->streamedContent()), true)[0]['name'])->toBe('Packed');

        $plain = $this->withHeaders(['Accept-Encoding' => ''])->get(route('playlist', ['token' => $user->playlist_token]));
        expect($plain->headers->has('Content-Encoding'))->toBeFalse();
    });

    it('lists favourites first and then the shared list, as valid JSON', function () {
        $user = User::factory()->create();
        $a = subscribedChannel($user, ['name' => 'A']);
        subscribedChannel($user, ['name' => 'B']);
        DB::table('user_favorites')->insert(['user_id' => $user->id, 'channel_id' => $a->id, 'position' => 1]);

        $streams = json_decode($this->get(route('xtream.api', ['username' => $user->username, 'password' => 'password', 'action' => 'get_live_streams']))->streamedContent(), true);

        expect(array_column($streams, 'name'))->toBe(['A', 'A', 'B']);
    });
});

it('remembers a right app password instead of checking the hash every time', function () {
    $user = User::factory()->create();
    subscribedChannel($user);
    // Three logins with the right password and one with a wrong one: two hash checks
    Hash::shouldReceive('check')->twice()->andReturnUsing(fn ($value, $hashed) => password_verify($value, $hashed));

    foreach (range(1, 3) as $i) {
        $this->get(route('xtream.api', ['username' => $user->username, 'password' => 'password']))->assertJsonPath('user_info.auth', 1);
    }
    $this->get(route('xtream.api', ['username' => $user->username, 'password' => 'wrong']))->assertJsonPath('user_info.auth', 0);
});
