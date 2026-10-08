<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'iptv:backup';

    protected $description = 'Back up the database to the backup disk and remove old backups';

    public function handle(BackupService $backups): int
    {
        try {
            $this->info('Backup written: '.$backups->run());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
