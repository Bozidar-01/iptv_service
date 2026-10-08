<?php

use App\Models\ActiveSession;
use App\Models\LoginEvent;
use App\Models\StreamLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete stale active_sessions rows and old play history (see ActiveSession::prunable, StreamLog::prunable, LoginEvent::prunable)
Schedule::command('model:prune', ['--model' => [ActiveSession::class, StreamLog::class, LoginEvent::class]])->everyFifteenMinutes()->withoutOverlapping();

// Refresh M3U sources that have auto-sync enabled (see Source::isDueForSync)
Schedule::command('iptv:sync-sources --due')->hourly();

// Heartbeat for /health and alerts when queued jobs are stuck or failing (see HealthService)
Schedule::command('iptv:heartbeat')->everyMinute();

// Alert when a satellite receiver's relay stops answering (see ReceiverService)
Schedule::command('iptv:check-receivers')->everyFiveMinutes();

// Remind admins about subscriptions ending soon / just ended (SubscriptionReminderService)
Schedule::command('iptv:expiry-reminders')->dailyAt('08:00');

// Refresh the TV guides daily and match channels to them (EpgImportService, EpgService)
Schedule::command('iptv:sync-epg')->dailyAt('03:30');

// Switch dead streams off and recovered ones back on (see StreamCheckService)
Schedule::command('iptv:check-streams')->dailyAt('04:00');
Schedule::command('iptv:check-streams --watched')->everyFifteenMinutes();

// Back up the database every night (BackupService); failures raise an alert
Schedule::command('iptv:backup')->dailyAt('02:30');

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
