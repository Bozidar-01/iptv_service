<?php

use App\Models\ActiveSession;
use App\Models\Category;
use App\Models\Channel;
use App\Models\StreamLog;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'tv1', 'password' => bcrypt('secret'), 'max_connections' => 1]);
    $this->news = Category::create(['name' => 'News']);
    $this->sport = Category::create(['name' => 'Sport']);
    $this->kanal5 = subscribedChannel($this->user, ['name' => 'Kanal 5', 'category_id' => $this->news->id, 'tvg_id' => 'Kanal5.mk', 'logo_url' => 'http://logo.test/k5.png']);
    $this->arena = subscribedChannel($this->user, ['name' => 'Arena', 'category_id' => $this->sport->id, 'stream_url' => 'http://upstream.test/arena.ts']);
    $this->loose = subscribedChannel($this->user, ['name' => 'Loose']);
});

function api(array $params = []): TestResponse
{
    return test()->get('/player_api.php?'.http_build_query(array_merge(['username' => 'tv1', 'password' => 'secret'], $params)));
}

it('logs in and returns user and server info', function () {
    $this->user->update(['expiration_date' => now()->addMonth()]);

    api()->assertOk()
        ->assertJsonPath('user_info.auth', 1)
        ->assertJsonPath('user_info.status', 'Active')
        ->assertJsonPath('user_info.username', 'tv1')
        ->assertJsonPath('user_info.password', 'secret')
        ->assertJsonPath('user_info.max_connections', '1')
        ->assertJsonPath('user_info.exp_date', (string) $this->user->fresh()->expiration_date->timestamp)
        ->assertJsonPath('server_info.url', 'localhost')
        ->assertJsonPath('server_info.server_protocol', 'http');
});

it('tells apps the port and protocol it was reached on, so https is never paired with port 80', function () {
    $login = fn (string $base) => test()->getJson($base.'/player_api.php?'.http_build_query(['username' => 'tv1', 'password' => 'secret']));

    $login('https://nebotv.test')->assertJsonPath('server_info.server_protocol', 'https')->assertJsonPath('server_info.port', '443')->assertJsonPath('server_info.https_port', '443');
    $login('http://nebotv.test')->assertJsonPath('server_info.server_protocol', 'http')->assertJsonPath('server_info.port', '80')->assertJsonPath('server_info.https_port', '443');
    $login('http://nebotv.test:8000')->assertJsonPath('server_info.port', '8000');
});

it('rejects a wrong password, an unknown user and missing credentials', function () {
    api(['password' => 'wrong'])->assertOk()->assertExactJson(['user_info' => ['auth' => 0]]);
    api(['username' => 'nobody'])->assertExactJson(['user_info' => ['auth' => 0]]);
    $this->get('/player_api.php')->assertExactJson(['user_info' => ['auth' => 0]]);
});

it('accepts POST without a CSRF token and creates no session cookie', function () {
    $this->post('/player_api.php', ['username' => 'tv1', 'password' => 'secret'])
        ->assertOk()->assertJsonPath('user_info.auth', 1)
        ->assertCookieMissing(config('session.cookie'));
});

it('reports disabled and expired accounts and gives them no channels', function () {
    $this->user->update(['status' => 'disabled']);
    api()->assertJsonPath('user_info.status', 'Disabled');
    api(['action' => 'get_live_streams'])->assertExactJson([]);

    $this->user->update(['status' => 'active', 'expiration_date' => now()->subDay()]);
    api()->assertJsonPath('user_info.status', 'Expired');
    api(['action' => 'get_live_categories'])->assertExactJson([]);
});

it('lists only the categories the user can watch', function () {
    Category::create(['name' => 'Empty']);
    $this->arena->update(['is_active' => false]);

    api(['action' => 'get_live_categories'])->assertExactJson([
        ['category_id' => (string) $this->news->id, 'category_name' => 'News', 'parent_id' => 0],
        ['category_id' => '0', 'category_name' => 'General', 'parent_id' => 0],
    ]);
});

it('lists live streams, optionally by category', function () {
    Channel::create(['name' => 'Not mine', 'stream_url' => 'http://x.test/a', 'is_active' => true]);

    $all = api(['action' => 'get_live_streams'])->assertOk()->streamedContent();
    expect(collect(json_decode($all, true))->pluck('name')->all())->toBe(['Loose', 'Kanal 5', 'Arena']);

    $news = json_decode(api(['action' => 'get_live_streams', 'category_id' => $this->news->id])->streamedContent(), true);
    expect($news)->toHaveCount(1)
        ->and($news[0])->toMatchArray([
            'num' => 1, 'name' => 'Kanal 5', 'stream_type' => 'live', 'stream_id' => $this->kanal5->id,
            'stream_icon' => 'http://logo.test/k5.png', 'epg_channel_id' => 'Kanal5.mk', 'category_id' => (string) $this->news->id,
        ]);

    $general = json_decode(api(['action' => 'get_live_streams', 'category_id' => 0])->streamedContent(), true);
    expect(collect($general)->pluck('name')->all())->toBe(['Loose']);
});

it('answers VOD, series and EPG actions with empty data', function () {
    foreach (['get_vod_categories', 'get_vod_streams', 'get_series_categories', 'get_series', 'unknown'] as $action) {
        api(['action' => $action])->assertExactJson([]);
    }
    api(['action' => 'get_short_epg', 'stream_id' => $this->kanal5->id])->assertExactJson(['epg_listings' => []]);
    expect($this->get('/xmltv.php?username=tv1&password=secret')->assertOk()->streamedContent())->toContain('<tv');
});

it('serves the M3U playlist from get.php', function () {
    $response = $this->get('/get.php?username=tv1&password=secret&type=m3u_plus&output=ts')->assertOk();
    expect($response->streamedContent())->toContain('#EXTM3U')->toContain('Kanal 5');
    $this->get('/get.php?username=tv1&password=wrong')->assertStatus(401);
    $this->get('/get.php')->assertStatus(401);
});

it('plays a live stream with the same checks as /play', function () {
    $this->get("/live/tv1/secret/{$this->arena->id}.ts")->assertRedirect('http://upstream.test/arena.ts');

    expect(StreamLog::where('channel_id', $this->arena->id)->count())->toBe(1)
        ->and(ActiveSession::where('user_id', $this->user->id)->count())->toBe(1);

    // Connection limit of 1: a second device is refused while the first is active
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->get("/live/tv1/secret/{$this->kanal5->id}.m3u8")->assertStatus(403);
});

it('refuses live streams for wrong passwords, other channels and unknown ids', function () {
    $other = Channel::create(['name' => 'Not mine', 'stream_url' => 'http://x.test/a', 'is_active' => true]);

    $this->get("/live/tv1/wrong/{$this->arena->id}.ts")->assertStatus(401);
    $this->get("/live/tv1/secret/{$other->id}.ts")->assertStatus(403);
    $this->get('/live/tv1/secret/999999.ts')->assertStatus(404);
});

it('shows the login details on the user page', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.users.show', $this->user))
        ->assertOk()
        ->assertSee('Log in with username and password')
        ->assertSee('value="'.url('/').'"', false)
        ->assertSee('value="tv1"', false);
});
