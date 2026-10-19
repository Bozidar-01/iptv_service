<?php

namespace App\Console\Commands;

use App\Services\StatisticsService;
use Illuminate\Console\Command;

class SampleViewingCommand extends Command
{
    protected $signature = 'iptv:sample-viewing';

    protected $description = 'Count the streams playing now, per source, for the Statistics page';

    public function handle(StatisticsService $statistics): int
    {
        $this->info('Streams playing now: '.$statistics->sample());

        return self::SUCCESS;
    }
}
