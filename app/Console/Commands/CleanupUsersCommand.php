<?php

namespace App\Console\Commands;

use App\Services\UserCleanupService;
use Illuminate\Console\Command;

class CleanupUsersCommand extends Command
{
    protected $signature = 'iptv:cleanup-users';

    protected $description = 'Delete old trials and switch off subscribers whose subscription ended long ago';

    public function handle(UserCleanupService $cleanup): int
    {
        $result = $cleanup->run();
        $this->info("Trials deleted: {$result['deleted']}, users switched off: {$result['disabled']}");

        return self::SUCCESS;
    }
}
