<?php

use App\Models\Channel;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\StatisticsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->anna = User::factory()->create(['username' => 'anna']);
    $this->boris = User::factory()->create(['username' => 'boris']);
    $this->news = Channel::create(['name' => 'News 24', 'stream_url' => 'http://s.test/1.ts']);
    $this->sport = Channel::create(['name' => 'Sport 1', 'stream_url' => 'http://s.test/2.ts']);
});

function play(User $user, Channel $channel, $at, int $times = 1): void
{
    StreamLog::insert(array_fill(0, $times, ['user_id' => $user->id, 'channel_id' => $channel->id, 'started_at' => $at]));
}

it('counts plays, viewers and channels for the period only', function () {
    play($this->anna, $this->news, now()->subDays(2)->setTime(20, 15), 3);
    play($this->boris, $this->news, now()->subDay()->setTime(20, 45), 2);
    play($this->boris, $this->sport, now()->setTime(9, 5));
    play($this->anna, $this->sport, now()->subDays(40)); // outside 30 days

    $report = app(StatisticsService::class)->report(30);

    expect($report['plays'])->toBe(6)
        ->and($report['viewers'])->toBe(2)
        ->and($report['channels'])->toBe(2)
        ->and($report['per_day'])->toHaveCount(30)
        ->and($report['per_day'][now()->subDays(2)->toDateString()])->toBe(3)
        ->and($report['per_day'][now()->subDays(5)->toDateString()])->toBe(0)
        ->and($report['per_hour'][20])->toBe(5)
        ->and($report['per_hour'][9])->toBe(1)
        ->and($report['per_hour'][3])->toBe(0);

    expect(app(StatisticsService::class)->report(90)['plays'])->toBe(7);
});

it('ranks the most played channels and the most active users', function () {
    play($this->anna, $this->news, now()->subDay(), 3);
    play($this->boris, $this->news, now()->subDay(), 2);
    play($this->boris, $this->sport, now()->subDay(), 4);

    $report = app(StatisticsService::class)->report(7);

    expect($report['top_channels'])->toBe([
        ['name' => 'News 24', 'plays' => 5, 'viewers' => 2],
        ['name' => 'Sport 1', 'plays' => 4, 'viewers' => 1],
    ])->and($report['top_users'])->toBe([
        ['username' => 'boris', 'plays' => 6, 'channels' => 2],
        ['username' => 'anna', 'plays' => 3, 'channels' => 1],
    ]);
});

it('shows the page with numbers, names and charts', function () {
    play($this->anna, $this->news, now()->subDay(), 3);

    $this->actingAs($this->admin)->get(route('admin.statistics.index'))
        ->assertOk()
        ->assertSee('Statistics')
        ->assertSee('News 24')
        ->assertSee('anna')
        ->assertSee('Plays per day')
        ->assertSee('Busiest hours')
        ->assertSee('Last 30 days');
});

it('says so when nothing was played', function () {
    $this->actingAs($this->admin)->get(route('admin.statistics.index', ['days' => 7]))
        ->assertOk()
        ->assertSee('No plays in this period')
        ->assertDontSee('Most played channels');
});

it('only accepts the offered periods', function () {
    $this->actingAs($this->admin);

    expect(app(StatisticsService::class)->report(5)['days'])->toBe(30);
    $this->get(route('admin.statistics.index', ['days' => 'x']))->assertOk()->assertSee('Last 30 days');
    $this->get(route('admin.statistics.index', ['days' => 90]))->assertOk();
});

it('forgets the plays of a deleted channel or user along with them', function () {
    play($this->anna, $this->news, now()->subDay(), 2);
    $this->news->delete();
    $this->anna->delete();

    $report = app(StatisticsService::class)->report(7);

    expect($report['plays'])->toBe(0); // the logs go with them (foreign keys cascade)
});

it('uses a fixed number of queries however many plays there are', function () {
    play($this->anna, $this->news, now()->subDay(), 600);
    play($this->boris, $this->sport, now()->subDays(3), 600);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    app(StatisticsService::class)->report(30);

    // days, hours, top channels (+names), top users (+names), viewers, channels
    expect($queries)->toBeLessThanOrEqual(8);
});

it('is cached for a few minutes', function () {
    play($this->anna, $this->news, now()->subDay());
    expect(app(StatisticsService::class)->report(30)['plays'])->toBe(1);

    play($this->anna, $this->news, now()->subDay());
    expect(app(StatisticsService::class)->report(30)['plays'])->toBe(1);

    $this->travel(6)->minutes();
    expect(app(StatisticsService::class)->report(30)['plays'])->toBe(2);
});

it('is for admins only', function () {
    $this->get(route('admin.statistics.index'))->assertRedirect(route('login'));
    $this->actingAs($this->anna)->get(route('admin.statistics.index'))->assertRedirect();
});

it('survives being read back from a cache that serializes, in either language', function () {
    // The default test cache keeps objects as they are; the real (database) one serializes and refuses objects
    config(['cache.default' => 'database']);
    play($this->anna, $this->news, now()->subDay(), 3);
    $this->actingAs($this->admin);

    $first = app(StatisticsService::class)->report(30);
    $second = app(StatisticsService::class)->report(30); // read back from the database

    expect($second)->toBe($first)->and($second['from'])->toBeString();

    $this->get(route('admin.statistics.index'))->assertOk()->assertSee('News 24');
    $this->get(route('admin.statistics.index'))->assertOk(); // the request that used to fail

    // Switching language is what reloaded the page in the failing case: both languages must keep working
    $this->withCookie('locale', 'mk')->get(route('admin.statistics.index'))->assertOk()->assertSee('Статистика');
    $this->withCookie('locale', 'en')->get(route('admin.statistics.index'))->assertOk()->assertSee('Statistics');
});

it('holds nothing but plain values in the cache', function () {
    play($this->anna, $this->news, now()->subDay());
    app(StatisticsService::class)->report(30);

    $walk = function ($value) use (&$walk) {
        expect(is_object($value))->toBeFalse();
        is_array($value) && array_map($walk, $value);
    };
    $walk(Cache::get('statistics:v2:30'));
});
