<?php

namespace App\Jobs;

use App\Models\EpgSource;
use App\Models\Source;
use App\Services\EpgImportService;
use App\Services\EpgService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncEpgSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public EpgSource $epgSource) {}

    public function uniqueId(): string
    {
        return (string) $this->epgSource->id;
    }

    public function handle(EpgImportService $importer, EpgService $epg): void
    {
        $importer->sync($this->epgSource);
        $epg->matchChannels();
    }

    public function failed(?Throwable $e): void
    {
        $source = $this->epgSource->fresh();

        if ($source && $source->status !== Source::STATUS_FAILED) {
            $source->update(['status' => Source::STATUS_FAILED, 'last_error' => 'The import was stopped before it finished.']);
        }
    }
}
