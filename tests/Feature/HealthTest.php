<?php

use App\Models\StreamAlert;
use App\Models\User;
use App\Services\HealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function queueJob(int $minutesAgo): void
{
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->subMinutes($minutesAgo)->timestamp, 'created_at' => now()->subMinutes($minutesAgo)->timestamp,
    ]);
}

beforeEach(fn () => config(['queue.default' => 'database']));

it('reports healthy when the scheduler ran recently and the queue is moving', function () {
    $this->artisan('iptv:heartbeat')->assertSuccessful();
    queueJob(2);

    $this->get('/health')->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('scheduler.ok', true)
        ->assertJsonPath('queue.oldest_job_minutes', 2);
});

it('reports a stopped scheduler', function () {
    $this->get('/health')->assertStatus(503)->assertJsonPath('scheduler.ok', false)->assertJsonPath('scheduler.last_run', null);

    $this->artisan('iptv:heartbeat');
    $this->travel(HealthService::SCHEDULER_STALE_MINUTES + 1)->minutes();

    $this->get('/health')->assertStatus(503)->assertJsonPath('scheduler.ok', false);
});

it('reports and alerts once when queued jobs are stuck', function () {
    queueJob(HealthService::QUEUE_STALE_MINUTES + 5);

    $this->artisan('iptv:heartbeat');
    $this->artisan('iptv:heartbeat');

    $this->get('/health')->assertStatus(503)->assertJsonPath('queue.ok', false);
    expect(StreamAlert::where('type', 'queue_stuck')->count())->toBe(1)
        ->and(StreamAlert::first()->message())->toBe('Background jobs are not running: the oldest has waited 20 minutes');
});

it('alerts when background jobs fail', function () {
    $this->artisan('iptv:heartbeat'); // first run only remembers the time
    $this->travel(1)->minutes();
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    $this->travel(1)->minutes();

    $this->artisan('iptv:heartbeat');
    $this->artisan('iptv:heartbeat');

    expect(StreamAlert::where('type', 'jobs_failed')->sole()->message())->toBe('1 background job failed');
});

it('warns on the dashboard when background tasks are not running', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.dashboard'))->assertSee('Background tasks are not running.');

    $this->artisan('iptv:heartbeat');
    $this->get(route('admin.dashboard'))->assertDontSee('Background tasks are not running.');
});

it('is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'iptv:heartbeat'));

    expect($event?->expression)->toBe('* * * * *');
});
