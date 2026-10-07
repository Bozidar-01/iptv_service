<?php

namespace App\Console\Commands;

use App\Services\ReceiverService;
use Illuminate\Console\Command;

class CheckReceiversCommand extends Command
{
    protected $signature = 'iptv:check-receivers';

    protected $description = 'Check that satellite receiver relays answer, and alert when one goes offline or comes back';

    public function handle(ReceiverService $receivers): int
    {
        $receivers->checkAll();

        return self::SUCCESS;
    }
}
