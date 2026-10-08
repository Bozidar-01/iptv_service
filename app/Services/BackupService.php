<?php

namespace App\Services;

use App\Models\StreamAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Database backups (`iptv:backup`): a gzipped SQL dump written to the backup disk (BACKUP_DISK, default
 * local) and, when BACKUP_COPY_DISK is set, also to a second disk, normally off-site (s3: AWS S3,
 * Backblaze B2, Cloudflare R2...). Old dumps are removed after BACKUP_KEEP_DAYS on each disk.
 * Data of tables that can be rebuilt (queue, cache, sessions, TV guide programmes) is left out,
 * their structure is kept so the dump restores into a working database.
 * Restore steps: docs/deployment.md#backups.
 */
class BackupService
{
    public const DIRECTORY = 'backups';

    /** Cache key: when the last backup finished on every configured disk. */
    public const LAST_SUCCESS_KEY = 'backup:last-success';

    /** Hours without a successful backup before HealthService raises an alert. */
    public const STALE_HOURS = 36;

    /** Tables whose rows aren't worth backing up (rebuilt by the app). */
    public const SKIP_DATA = ['jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'epg_programmes'];

    /**
     * Create a backup and remove expired ones. Returns the stored file name.
     * Failures are reported as an alert and thrown.
     */
    public function run(): string
    {
        try {
            $name = 'iptv-'.now()->format('Ymd-His').'.sql.gz';
            $tmp = tempnam(sys_get_temp_dir(), 'iptv-backup');

            try {
                $this->dump($tmp);
                $failed = [];
                foreach ($this->disks() as $diskName) {
                    try {
                        $this->store($diskName, $tmp, $name);
                    } catch (\Throwable $e) {
                        // Keep going: the other disk may still get its copy
                        report($e);
                        $failed[] = $diskName;
                    }
                }
            } finally {
                @unlink($tmp);
            }

            $this->prune();

            if ($failed) {
                throw new RuntimeException('Not written to: '.implode(', ', $failed));
            }

            Cache::forever(self::LAST_SUCCESS_KEY, now()->timestamp);

            return $name;
        } catch (\Throwable $e) {
            report($e);
            StreamAlert::create(['type' => StreamAlert::TYPE_BACKUP_FAILED, 'name' => 'Backup', 'detail' => mb_substr($e->getMessage(), 0, 50)]);

            throw $e;
        }
    }

    /** The disks a backup goes to: the main one, and the off-site copy when configured. */
    public function disks(): array
    {
        return array_values(array_unique(array_filter([config('iptv.backup.disk'), config('iptv.backup.copy_disk')])));
    }

    /** When the last backup succeeded on every disk, or null when none has been recorded yet. */
    public function lastSuccess(): ?Carbon
    {
        $at = Cache::get(self::LAST_SUCCESS_KEY);

        return $at ? Carbon::createFromTimestamp($at) : null;
    }

    private function store(string $diskName, string $file, string $name): void
    {
        $disk = Storage::disk($diskName);
        $stream = fopen($file, 'rb');
        $written = $disk->writeStream(self::DIRECTORY.'/'.$name, $stream);
        is_resource($stream) && fclose($stream);

        // Disks don't throw by default: a full disk, bad permissions or a wrong bucket only return false
        if (!$written || !$disk->exists(self::DIRECTORY.'/'.$name)) {
            throw new RuntimeException("The backup could not be written to the disk \"{$diskName}\".");
        }
    }

    /**
     * Delete backups older than BACKUP_KEEP_DAYS on every disk, but never the newest one.
     *
     * @return int files deleted
     */
    public function prune(): int
    {
        return array_sum(array_map(fn ($diskName) => $this->pruneDisk($diskName), $this->disks()));
    }

    private function pruneDisk(string $diskName): int
    {
        $disk = Storage::disk($diskName);
        $files = collect($disk->files(self::DIRECTORY))
            ->filter(fn ($path) => preg_match('/iptv-\d{8}-\d{6}\.sql\.gz$/', $path))
            ->sort()
            ->values();

        $cutoff = now()->subDays(config('iptv.backup.keep_days'));
        $old = $files->slice(0, max(0, $files->count() - 1))
            ->filter(fn ($path) => $disk->lastModified($path) < $cutoff->timestamp);

        $old->each(fn ($path) => $disk->delete($path));

        return $old->count();
    }

    private function dump(string $target): void
    {
        $config = config('database.connections.'.(config('iptv.backup.connection') ?: config('database.default')));

        if ($config['driver'] === 'sqlite') {
            // A single file: just compress it
            file_put_contents('compress.zlib://'.$target, file_get_contents($config['database']));

            return;
        }

        if ($config['driver'] !== 'mysql') {
            throw new RuntimeException('Backups support MySQL and SQLite only.');
        }

        $out = gzopen($target, 'wb9');

        try {
            // Structure of everything, then the rows of every table except the skipped ones
            $this->mysqldump($config, ['--no-data'], $out);
            $this->mysqldump($config, array_merge(['--no-create-info'], array_map(fn ($table) => '--ignore-table='.$config['database'].'.'.$table, self::SKIP_DATA)), $out);
        } finally {
            gzclose($out);
        }
    }

    private function mysqldump(array $config, array $options, $out): void
    {
        $command = array_merge(
            [config('iptv.backup.mysqldump'), '--single-transaction', '--no-tablespaces', '--skip-comments',
                '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username']],
            config('iptv.backup.dump_options'),
            $options,
            [$config['database']],
        );

        $process = new Process($command, null, ['MYSQL_PWD' => (string) $config['password']], null, 600);
        $process->run(function ($type, $buffer) use ($out) {
            if ($type === Process::OUT && gzwrite($out, $buffer) === 0) {
                throw new RuntimeException('Could not write the backup file (disk full?).');
            }
        });

        if (!$process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }
    }
}
