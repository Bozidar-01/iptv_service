<?php

namespace App\Console\Commands;

use App\Services\StreamCheckService;
use Illuminate\Console\Command;

class CheckStreamsCommand extends Command
{
    protected $signature = 'iptv:check-streams
                            {source? : Only check channels of this source ID}
                            {--watched : Only channels that have alerts turned on}
                            {--now : Run in this process instead of queueing}';

    protected $description = 'Check channel streams, switch dead ones off and recovered ones back on';

    public function handle(StreamCheckService $checker): int
    {
        $source = $this->argument('source') ? (int) $this->argument('source') : null;
        $watched = (bool) $this->option('watched');

        if (!$this->option('now')) {
            $count = $checker->queueCheck($source, $watched);
            if ($count === null) {
                $this->warn('A stream check is already running.');

                return self::SUCCESS;
            }
            $this->info("Queued a check of {$count} channels.");

            return self::SUCCESS;
        }

        $online = $offline = 0;
        $bar = $this->output->createProgressBar($checker->checkableQuery($source, $watched)->count());

        $checker->checkableQuery($source, $watched)->select('id')->chunkById(StreamCheckService::CHUNK_SIZE, function ($channels) use ($checker, $bar, &$online, &$offline) {
            $result = $checker->checkChannels($channels->pluck('id')->all());
            $online += $result['online'];
            $offline += $result['offline'];
            $bar->advance($channels->count());
        });

        $bar->finish();
        $this->newLine();
        $this->info("Online: {$online}, offline: {$offline}.");

        return self::SUCCESS;
    }
}
