<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\SourceSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Large provider playlists can take a while; keep below the queue's retry_after.
     */
    public int $timeout = 600;

    public function __construct(public Source $source) {}

    public function uniqueId(): string
    {
        return (string) $this->source->id;
    }

    public function handle(SourceSyncService $syncService): void
    {
        $syncService->sync($this->source);
    }

    /**
     * Normal import errors are recorded by SourceSyncService. This covers a worker killed
     * mid-import (e.g. a host's process limit) or a timeout, so the source doesn't stay
     * "Syncing" forever.
     */
    public function failed(?Throwable $e): void
    {
        $source = $this->source->fresh();

        if ($source && $source->status !== Source::STATUS_FAILED) {
            $source->update([
                'status' => Source::STATUS_FAILED,
                'last_error' => 'The import was stopped before it finished.',
            ]);
        }
    }
}
