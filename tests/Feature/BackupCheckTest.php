<?php

use App\Models\ChecklistTick;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
    Storage::fake('offsite');
    config(['iptv.backup.disk' => 'backups', 'iptv.backup.copy_disk' => null, 'iptv.backup.connection' => null]);
    $this->admin = User::factory()->admin()->create();
});

/** A MySQL-style dump with every table of the database and some users. */
function dumpText(array $without = [], bool $users = true): string
{
    $tables = array_diff(array_map(fn ($t) => preg_replace('/^.*\./', '', $t), Schema::getTableListing(null, false)), $without);
    $sql = '';
    foreach ($tables as $table) {
        $sql .= "CREATE TABLE `$table` (\n  `id` int NOT NULL\n);\n";
    }

    return $sql.($users ? "INSERT INTO `users` VALUES (1,'admin');\n" : '');
}

function putBackup(string $disk, string $content, string $name = 'iptv-20261015-023000.sql.gz'): void
{
    Storage::disk($disk)->put('backups/'.$name, $content);
}

describe('checking a backup', function () {
    it('passes a complete dump with every table and the users', function () {
        putBackup('backups', gzencode(dumpText()));

        $result = app(BackupService::class)->verify('backups');

        expect($result['ok'])->toBeTrue()->and($result['file'])->toBe('iptv-20261015-023000.sql.gz')
            ->and(array_column($result['checks'], 'ok'))->each->toBeTrue()
            ->and(array_column($result['checks'], 'label'))->toBe(['A backup file exists', 'It is not empty', 'It reads to the end (not cut off)', 'It has every table', 'It has the users']);
    });

    it('fails with nothing on the disk or an empty file', function () {
        expect(app(BackupService::class)->verify('backups'))->ok->toBeFalse()->file->toBeNull();

        putBackup('backups', '');
        $result = app(BackupService::class)->verify('backups');
        expect($result['ok'])->toBeFalse()->and(collect($result['checks'])->firstWhere('label', 'It is not empty')['ok'])->toBeFalse();
    });

    it('fails a file that is cut off', function () {
        $full = gzencode(str_repeat(dumpText(), 200));
        putBackup('backups', substr($full, 0, (int) (strlen($full) * 0.6)));

        $result = app(BackupService::class)->verify('backups');

        expect($result['ok'])->toBeFalse()->and(collect($result['checks'])->firstWhere('label', 'It reads to the end (not cut off)')['ok'])->toBeFalse();
    });

    it('names the tables that are missing and the users that are not there', function () {
        putBackup('backups', gzencode(dumpText(without: ['channels', 'bouquets'], users: false)));

        $result = app(BackupService::class)->verify('backups');
        $byLabel = collect($result['checks'])->keyBy('label');

        expect($result['ok'])->toBeFalse()->and($byLabel['It has every table']['ok'])->toBeFalse()->and($byLabel['It has every table']['detail'])->toContain('channels')->toContain('bouquets')
            ->and($byLabel['It has the users']['ok'])->toBeFalse();
    });

    it('accepts a gzipped SQLite file', function () {
        putBackup('backups', gzencode("SQLite format 3\0".str_repeat('x', 500)));

        expect(app(BackupService::class)->verify('backups')['ok'])->toBeTrue();
    });

    it('checks the newest backup, not an older one', function () {
        putBackup('backups', gzencode('broken'), 'iptv-20261001-023000.sql.gz');
        putBackup('backups', gzencode(dumpText()), 'iptv-20261015-023000.sql.gz');

        expect(app(BackupService::class)->verify('backups'))->ok->toBeTrue()->file->toBe('iptv-20261015-023000.sql.gz');
    });
});

describe('every disk', function () {
    it('remembers a pass and ticks the launch checklist', function () {
        putBackup('backups', gzencode(dumpText()));

        expect(app(BackupService::class)->lastVerified())->toBeNull();
        $results = app(BackupService::class)->verifyAll($this->admin);

        expect($results)->toHaveCount(1)->and(app(BackupService::class)->lastVerified())->not->toBeNull()
            ->and(ChecklistTick::where('item', 'restore_tested')->value('user_id'))->toBe($this->admin->id);
    });

    it('does not remember or tick a failure', function () {
        putBackup('backups', gzencode('nothing useful'));

        app(BackupService::class)->verifyAll($this->admin);

        expect(app(BackupService::class)->lastVerified())->toBeNull()->and(ChecklistTick::count())->toBe(0);
    });

    it('checks the off-site copy too and wants the same newest file on both', function () {
        config(['iptv.backup.copy_disk' => 'offsite']);
        putBackup('backups', gzencode(dumpText()), 'iptv-20261015-023000.sql.gz');
        putBackup('offsite', gzencode(dumpText()), 'iptv-20261015-023000.sql.gz');
        expect(collect(app(BackupService::class)->verifyAll())->pluck('ok')->all())->toBe([true, true]);

        putBackup('offsite', gzencode(dumpText()), 'iptv-20261010-023000.sql.gz');
        Storage::disk('offsite')->delete('backups/iptv-20261015-023000.sql.gz');
        $results = app(BackupService::class)->verifyAll();

        expect(collect($results)->pluck('ok')->all())->toBe([false, false])
            ->and(collect($results[0]['checks'])->last()['label'])->toBe('Every disk has the same newest backup');
    });
});

describe('the page', function () {
    it('lists the backups and runs the check', function () {
        putBackup('backups', gzencode(dumpText()));

        $this->actingAs($this->admin)->get(route('admin.backups.index'))->assertOk()->assertSee('iptv-20261015-023000.sql.gz')->assertSee('There is no off-site copy')->assertSee('None yet');

        $response = $this->actingAs($this->admin)->post(route('admin.backups.verify'))->assertRedirect();
        $this->actingAs($this->admin)->get(route('admin.backups.index'))->assertSee('Check of backups')->assertSee('Passed')->assertSee('It has every table');
    });

    it('is for admins only and in the menu', function () {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee(route('admin.backups.index'), false);
        $this->actingAs(User::factory()->create(['role' => 'reseller']))->get(route('admin.backups.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'reseller']))->post(route('admin.backups.verify'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.backups.index'))->assertRedirect(route('login'));
    });
});
