<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\SourceSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSource implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    /**
     * Large provider playlists can take a while; keep below the queue's retry_after.
     */
    public int $timeout = 600;

    public function __construct(public Source $source)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->source->id;
    }

    public function handle(SourceSyncService $syncService): void
    {
        $syncService->sync($this->source);
    }
}
