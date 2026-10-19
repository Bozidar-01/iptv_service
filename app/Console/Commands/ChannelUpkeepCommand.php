<?php

namespace App\Console\Commands;

use App\Jobs\FillChannelInfo;
use App\Services\ChannelHealthService;
use App\Services\ChannelNumberService;
use App\Services\ListCacheService;
use App\Services\PlaylistOptionsService;
use Illuminate\Console\Command;

class ChannelUpkeepCommand extends Command
{
    protected $signature = 'iptv:channel-upkeep';

    protected $description = 'Daily: number new channels, work out better copies, delete old check history and unused channel lists, and refresh logos and guide ids weekly';

    public function handle(ChannelNumberService $numbers, PlaylistOptionsService $options, ChannelHealthService $health, ListCacheService $lists): int
    {
        $numbered = $numbers->fillMissing();
        $better = $options->settings()['hide_worse_copies'] ? $options->refreshBetterCopies() : 0;
        $pruned = $health->prune();
        $files = $lists->prune();
        // Downloads the iptv-org database itself when it is a week old
        FillChannelInfo::dispatch();

        $this->info("Numbered {$numbered} channels, {$better} have a better copy, deleted {$pruned} old check days and {$files} unused lists.");

        return self::SUCCESS;
    }
}
