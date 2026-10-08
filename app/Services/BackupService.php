<?php

namespace App\Services;

use App\Models\StreamAlert;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Database backups (`iptv:backup`): a gzipped SQL dump written to the backup disk
 * (BACKUP_DISK: local, or an off-site disk such as s3), old dumps removed after BACKUP_KEEP_DAYS.
 * Data of tables that can be rebuilt (queue, cache, sessions, TV guide programmes) is left out,
 * their structure is kept so the dump restores into a working database.
 * Restore steps: docs/deployment.md#backups.
 */
class BackupService
{
    public const DIRECTORY = 'backups';

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
                $stream = fopen($tmp, 'rb');
                Storage::disk(config('iptv.backup.disk'))->writeStream(self::DIRECTORY.'/'.$name, $stream);
                is_resource($stream) && fclose($stream);
            } finally {
                @unlink($tmp);
            }

            $this->prune();

            return $name;
        } catch (\Throwable $e) {
            report($e);
            StreamAlert::create(['type' => StreamAlert::TYPE_BACKUP_FAILED, 'name' => 'Backup', 'detail' => mb_substr($e->getMessage(), 0, 50)]);

            throw $e;
        }
    }

    /**
     * Delete backups older than BACKUP_KEEP_DAYS, but never the newest one.
     *
     * @return int files deleted
     */
    public function prune(): int
    {
        $disk = Storage::disk(config('iptv.backup.disk'));
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
            if ($type === Process::OUT) {
                gzwrite($out, $buffer);
            }
        });

        if (!$process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }
    }
}
