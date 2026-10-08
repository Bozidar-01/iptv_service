<?php

namespace App\Services;

use App\Models\StreamAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Notices when background work stops: the scheduler (cron) not running, queued jobs
 * waiting because no worker picks them up, or jobs failing. The scheduler itself can't
 * report that it stopped, so GET /health exposes the same checks to an outside monitor.
 */
class HealthService
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    private const FAILED_CHECKED_KEY = 'health:failed-jobs-checked-at';

    /** The scheduler runs every minute; older than this means cron stopped. */
    public const SCHEDULER_STALE_MINUTES = 5;

    /** A job waiting this long means no worker is processing the queue. */
    public const QUEUE_STALE_MINUTES = 15;

    /** Don't repeat the "queue stuck" alert more often than this. */
    private const QUEUE_ALERT_HOURS = 6;

    /**
     * Run by the scheduler every minute (iptv:heartbeat).
     */
    public function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->timestamp);

        $this->checkBackup();

        $waited = $this->oldestJobMinutes();
        if ($waited !== null && $waited >= self::QUEUE_STALE_MINUTES
            && Cache::add('health:queue-alerted', true, now()->addHours(self::QUEUE_ALERT_HOURS))) {
            StreamAlert::create(['type' => StreamAlert::TYPE_QUEUE_STUCK, 'name' => 'Queue', 'detail' => (string) $waited]);
        }

        $since = Cache::get(self::FAILED_CHECKED_KEY);
        Cache::forever(self::FAILED_CHECKED_KEY, now()->timestamp);
        if ($since !== null) {
            $failed = DB::table('failed_jobs')->where('failed_at', '>', date('Y-m-d H:i:s', $since))->count();
            if ($failed > 0) {
                StreamAlert::create(['type' => StreamAlert::TYPE_JOBS_FAILED, 'name' => 'Queue', 'detail' => (string) $failed]);
            }
        }
    }

    /**
     * Alert (once a day) when no backup has succeeded for BackupService::STALE_HOURS. A server that never
     * recorded one is measured from when it was first seen here, so a new install isn't alerted at once.
     */
    private function checkBackup(): void
    {
        $since = app(BackupService::class)->lastSuccess() ?? Carbon::createFromTimestamp(Cache::rememberForever('backup:watch-since', fn () => now()->timestamp));
        $hours = (int) $since->diffInHours(now());

        if ($hours >= BackupService::STALE_HOURS && Cache::add('health:backup-alerted', true, now()->addDay())) {
            StreamAlert::create(['type' => StreamAlert::TYPE_BACKUP_STALE, 'name' => 'Backup', 'detail' => (string) $hours]);
        }
    }

    /**
     * @return array{ok: bool, scheduler: array{ok: bool, last_run: ?string}, queue: array{ok: bool, oldest_job_minutes: ?int}}
     */
    public function status(): array
    {
        $lastRun = Cache::get(self::HEARTBEAT_KEY);
        $schedulerOk = $lastRun !== null && now()->timestamp - $lastRun < self::SCHEDULER_STALE_MINUTES * 60;

        $waited = $this->oldestJobMinutes();
        $queueOk = $waited === null || $waited < self::QUEUE_STALE_MINUTES;

        return [
            'ok' => $schedulerOk && $queueOk,
            'scheduler' => ['ok' => $schedulerOk, 'last_run' => $lastRun ? date(DATE_ATOM, $lastRun) : null],
            'queue' => ['ok' => $queueOk, 'oldest_job_minutes' => $waited],
        ];
    }

    /**
     * Minutes the oldest waiting job has been ready to run, or null when none is waiting.
     * Only the database queue can be inspected; other drivers report nothing.
     */
    private function oldestJobMinutes(): ?int
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'database') {
            return null;
        }

        $availableAt = DB::table(config('queue.connections.'.config('queue.default').'.table', 'jobs'))
            ->whereNull('reserved_at')
            ->min('available_at');

        return $availableAt ? max(0, intdiv(now()->timestamp - $availableAt, 60)) : null;
    }
}
