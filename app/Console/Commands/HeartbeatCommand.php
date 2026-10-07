<?php

namespace App\Console\Commands;

use App\Services\HealthService;
use Illuminate\Console\Command;

class HeartbeatCommand extends Command
{
    protected $signature = 'iptv:heartbeat';

    protected $description = 'Record that the scheduler runs, and alert when queued jobs are stuck or failing';

    public function handle(HealthService $health): int
    {
        $health->beat();

        return self::SUCCESS;
    }
}
