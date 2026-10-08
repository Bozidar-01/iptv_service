<?php

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create an active channel inside a bouquet assigned to the given user.
 */
function subscribedChannel(User $user, array $attributes = []): Channel
{
    $channel = Channel::create(array_merge([
        'name' => 'Test Channel',
        'stream_url' => 'https://upstream.test/stream.m3u8',
        'is_active' => true,
    ], $attributes));

    $bouquet = Bouquet::create(['name' => 'Pack '.$channel->id]);
    $bouquet->channels()->attach($channel);
    $user->bouquets()->attach($bouquet);

    return $channel;
}

/**
 * Http::fake() again with new stubs. A plain second Http::fake() call keeps the earlier stubs
 * answering first, so tests that change a fake response mid-test use this instead.
 */
function refakeHttp(array $stubs): void
{
    app()->instance(Factory::class, new Factory);
    Http::clearResolvedInstances();
    Http::fake($stubs);
}
