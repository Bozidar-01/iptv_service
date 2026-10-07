<?php

namespace App\Jobs;

use App\Services\StreamCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckStreams implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int>  $channelIds  at most StreamCheckService::CHUNK_SIZE
     */
    public function __construct(public array $channelIds)
    {
    }

    public function handle(StreamCheckService $checker): void
    {
        try {
            $checker->checkChannels($this->channelIds);
        } finally {
            $checker->jobFinished();
        }
    }

    /**
     * Killed or timed out (handle() never reached its finally): still count the job as done.
     */
    public function failed(?\Throwable $e): void
    {
        app(StreamCheckService::class)->jobFinished();
    }
}
