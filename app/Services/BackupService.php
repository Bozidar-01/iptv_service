<?php

namespace App\Services;

use App\Models\ChecklistTick;
use App\Models\StreamAlert;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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

    /** Cache key: when the newest backup last passed the check (verify). */
    public const VERIFIED_KEY = 'backup:verified-at';

    /**
     * The backups on a disk, newest first.
     *
     * @return list<array{path: string, name: string, size: int, at: int}>
     */
    public function list(string $diskName, int $limit = 10): array
    {
        $disk = Storage::disk($diskName);

        return collect($disk->files(self::DIRECTORY))
            ->filter(fn ($path) => preg_match('/iptv-\d{8}-\d{6}\.sql\.gz$/', $path))
            ->sortDesc()->values()->take($limit)
            ->map(fn ($path) => ['path' => $path, 'name' => basename($path), 'size' => (int) $disk->size($path), 'at' => (int) $disk->lastModified($path)])
            ->all();
    }

    /**
     * Check the newest backup on a disk without restoring it: it exists and isn't empty, the whole gzip file reads to its end (not cut
     * short), it holds every table the database has now, and the users' rows are in it. Not as good as a restore into a scratch
     * database, but it catches an empty, cut-off or unreadable file, which is how backups usually fail.
     *
     * @return array{disk: string, ok: bool, file: ?string, checks: list<array{label: string, ok: bool, detail: string}>}
     */
    public function verify(string $diskName): array
    {
        $checks = [];
        $check = function (string $label, bool $ok, string $detail = '') use (&$checks) {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];

            return $ok;
        };

        $newest = $this->list($diskName, 1)[0] ?? null;
        if (!$check(__('A backup file exists'), $newest !== null, $newest ? $newest['name'] : __('No backup on this disk yet.'))
            || !$check(__('It is not empty'), $newest['size'] > 0, $newest['size'].' bytes')) {
            return ['disk' => $diskName, 'ok' => false, 'file' => $newest['name'] ?? null, 'checks' => $checks];
        }

        $source = Storage::disk($diskName)->readStream($newest['path']);
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $tables = [];
        $usersRows = false;
        $header = '';
        $carry = '';

        try {
            while ($source && !feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $text = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
                if ($text === false) {
                    break;
                }
                $header = strlen($header) < 16 ? $header.$text : $header;
                $window = $carry.$text;
                if (preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/', $window, $m)) {
                    array_push($tables, ...$m[1]);
                }
                $usersRows = $usersRows || str_contains($window, 'INSERT INTO `users`');
                $carry = substr($window, -200);
            }
        } finally {
            is_resource($source) && fclose($source);
        }

        $complete = inflate_get_status($context) === ZLIB_STREAM_END;
        $check(__('It reads to the end (not cut off)'), $complete, $complete ? '' : __('The file ends too early or is damaged.'));

        if (str_starts_with($header, 'SQLite format 3')) {
            $check(__('It is a database file'), true, 'SQLite');
        } else {
            $expected = Schema::connection(config('iptv.backup.connection'))->getTableListing(null, false);
            $expected = array_map(fn ($t) => preg_replace('/^.*\./', '', $t), $expected);
            $missing = array_values(array_diff($expected, array_unique($tables)));
            $check(__('It has every table'), $missing === [], $missing ? __('Missing: :tables', ['tables' => implode(', ', $missing)]) : trans_choice(':count table|:count tables', count(array_unique($tables)), ['count' => count(array_unique($tables))]));
            $check(__('It has the users'), $usersRows, $usersRows ? '' : __('No rows of the users table.'));
        }

        return ['disk' => $diskName, 'ok' => !in_array(false, array_column($checks, 'ok'), true), 'file' => $newest['name'], 'checks' => $checks];
    }

    /**
     * Check the newest backup on every disk and that each disk has the same newest file. When all is well, remember it
     * (and tick "a restore has been tested" on the launch checklist).
     *
     * @return list<array{disk: string, ok: bool, file: ?string, checks: list<array{label: string, ok: bool, detail: string}>}>
     */
    public function verifyAll(?User $by = null): array
    {
        $results = array_map(fn ($disk) => $this->verify($disk), $this->disks());

        $names = array_unique(array_filter(array_column($results, 'file')));
        if (count($results) > 1 && count($names) > 1) {
            foreach ($results as &$result) {
                $result['checks'][] = ['label' => __('Every disk has the same newest backup'), 'ok' => false, 'detail' => implode(' / ', $names)];
                $result['ok'] = false;
            }
            unset($result);
        }

        if ($results && !in_array(false, array_column($results, 'ok'), true)) {
            Cache::forever(self::VERIFIED_KEY, now()->timestamp);
            ChecklistTick::firstOrCreate(['item' => 'restore_tested'], ['user_id' => $by?->id]);
        }

        return $results;
    }

    /** When the newest backup last passed the check, or null. */
    public function lastVerified(): ?Carbon
    {
        $at = Cache::get(self::VERIFIED_KEY);

        return $at ? Carbon::createFromTimestamp($at) : null;
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
