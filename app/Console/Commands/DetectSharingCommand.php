<?php

namespace App\Console\Commands;

use App\Services\SharingDetectionService;
use Illuminate\Console\Command;

class DetectSharingCommand extends Command
{
    protected $signature = 'iptv:detect-sharing';

    protected $description = 'Raise an alert for logins used from many different addresses (account sharing)';

    public function handle(SharingDetectionService $sharing): int
    {
        $this->info('Alerts raised: '.$sharing->detect());

        return self::SUCCESS;
    }
}
