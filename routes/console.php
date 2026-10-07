<?php

use App\Models\ActiveSession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete stale active_sessions rows (see ActiveSession::prunable)
Schedule::command('model:prune', ['--model' => [ActiveSession::class]])->everyFifteenMinutes();

// Refresh M3U sources that have auto-sync enabled (see Source::isDueForSync)
Schedule::command('iptv:sync-sources --due')->hourly();

// Heartbeat for /health and alerts when queued jobs are stuck or failing (see HealthService)
Schedule::command('iptv:heartbeat')->everyMinute();

// Switch dead streams off and recovered ones back on (see StreamCheckService)
Schedule::command('iptv:check-streams')->dailyAt('04:00');
Schedule::command('iptv:check-streams --watched')->everyFifteenMinutes();

// Email new alerts as one digest (ALERT_MAIL_TO, see docs/features/alerts.md)
Schedule::command('iptv:send-alerts')->everyFiveMinutes()->withoutOverlapping();

// Shared hosting has no long-running worker: let the schedule:run cron process queued
// imports (QUEUE_SCHEDULED_WORKER=true, see docs/deployment.md). Worst case is 240 s of
// polling + one 600 s job, under the 15-minute lock, which also frees a killed worker.
Schedule::command('queue:work', ['--stop-when-empty', '--tries=1', '--timeout=600', '--max-time=240'])
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->when(fn () => config('queue.scheduled_worker'));
