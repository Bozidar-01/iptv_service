<?php

use App\Models\StreamAlert;
use App\Services\BackupService;
use App\Services\HealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
    config(['iptv.backup.disk' => 'backups', 'iptv.backup.keep_days' => 14]);
});

it('writes a gzipped copy of an SQLite database to the backup disk', function () {
    $file = tempnam(sys_get_temp_dir(), 'db');
    file_put_contents($file, 'sqlite contents');
    config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $file], 'iptv.backup.connection' => 'backup_test']);

    $this->artisan('iptv:backup')->assertSuccessful();

    $files = Storage::disk('backups')->files('backups');
    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch('~backups/iptv-\d{8}-\d{6}\.sql\.gz$~')
        ->and(gzdecode(Storage::disk('backups')->get($files[0])))->toBe('sqlite contents');

    unlink($file);
});

it('raises an alert and fails when the dump fails', function () {
    // A connection that is only described, never opened: the dump program is missing
    config([
        'database.connections.backup_test' => ['driver' => 'mysql', 'host' => 'db.invalid', 'port' => 3306, 'username' => 'u', 'password' => 'p', 'database' => 'd'],
        'iptv.backup.connection' => 'backup_test',
        'iptv.backup.mysqldump' => '/nonexistent/mysqldump',
    ]);

    $this->artisan('iptv:backup')->assertFailed();

    expect(Storage::disk('backups')->files('backups'))->toBeEmpty()
        ->and(StreamAlert::where('type', 'backup_failed')->sole()->message())->toBe('The database backup failed');
});

it('keeps recent backups and removes old ones, but never the newest', function () {
    $disk = Storage::disk('backups');
    foreach (['20260101-000000', '20260102-000000', now()->format('Ymd-His')] as $stamp) {
        $disk->put("backups/iptv-$stamp.sql.gz", 'x');
    }
    $disk->put('backups/notes.txt', 'not a backup');
    touch($disk->path('backups/iptv-20260101-000000.sql.gz'), now()->subDays(30)->timestamp);
    touch($disk->path('backups/iptv-20260102-000000.sql.gz'), now()->subDays(29)->timestamp);

    expect(app(BackupService::class)->prune())->toBe(2)
        ->and($disk->files('backups'))->toHaveCount(2);

    // Everything is old, but the newest backup stays
    $newest = collect($disk->files('backups'))->filter(fn ($p) => str_ends_with($p, '.gz'))->first();
    touch($disk->path($newest), now()->subDays(60)->timestamp);
    expect(app(BackupService::class)->prune())->toBe(0)
        ->and($disk->exists($newest))->toBeTrue();
});

describe('off-site copy', function () {
    beforeEach(function () {
        Storage::fake('offsite');
        $file = tempnam(sys_get_temp_dir(), 'db');
        file_put_contents($file, 'sqlite contents');
        config([
            'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $file],
            'iptv.backup.connection' => 'backup_test',
            'iptv.backup.copy_disk' => 'offsite',
        ]);
    });

    it('writes every backup to both disks and records the success', function () {
        $this->artisan('iptv:backup')->assertSuccessful();

        $local = Storage::disk('backups')->files('backups');
        $remote = Storage::disk('offsite')->files('backups');
        expect($local)->toHaveCount(1)->and($remote)->toBe($local)
            ->and(gzdecode(Storage::disk('offsite')->get($remote[0])))->toBe('sqlite contents')
            ->and(app(BackupService::class)->lastSuccess())->not->toBeNull();
    });

    it('fails and alerts when the off-site copy cannot be written, but keeps the local backup', function () {
        config(['filesystems.disks.broken' => ['driver' => 'local', 'root' => '/dev/null/offsite', 'throw' => false], 'iptv.backup.copy_disk' => 'broken']);

        $this->artisan('iptv:backup')->assertFailed();

        expect(Storage::disk('backups')->files('backups'))->toHaveCount(1)
            ->and(StreamAlert::where('type', 'backup_failed')->count())->toBe(1)
            ->and(app(BackupService::class)->lastSuccess())->toBeNull();
    });

    it('removes old backups from both disks', function () {
        foreach (['backups', 'offsite'] as $name) {
            $disk = Storage::disk($name);
            $disk->put('backups/iptv-20260101-000000.sql.gz', 'x');
            $disk->put('backups/iptv-'.now()->format('Ymd-His').'.sql.gz', 'x');
            touch($disk->path('backups/iptv-20260101-000000.sql.gz'), now()->subDays(40)->timestamp);
        }

        expect(app(BackupService::class)->prune())->toBe(2)
            ->and(Storage::disk('backups')->files('backups'))->toHaveCount(1)
            ->and(Storage::disk('offsite')->files('backups'))->toHaveCount(1);
    });
});

describe('stale backup alert', function () {
    it('alerts once a day when no backup succeeded for a day and a half', function () {
        $health = app(HealthService::class);

        $health->beat(); // first time seen: the clock starts now
        expect(StreamAlert::where('type', 'backup_stale')->count())->toBe(0);

        $this->travel(37)->hours();
        $health->beat();
        $health->beat();
        expect(StreamAlert::where('type', 'backup_stale')->sole()->message())->toBe('No database backup for 37 hours');

        $this->travel(25)->hours();
        $health->beat();
        expect(StreamAlert::where('type', 'backup_stale')->count())->toBe(2);
    });

    it('stays quiet while backups succeed', function () {
        $health = app(HealthService::class);
        $health->beat();

        $this->travel(30)->hours();
        Cache::forever(BackupService::LAST_SUCCESS_KEY, now()->timestamp);
        $this->travel(30)->hours();
        $health->beat();

        expect(StreamAlert::where('type', 'backup_stale')->count())->toBe(0);
    });
});
