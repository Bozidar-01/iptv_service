<?php

namespace App\Console\Commands;

use App\Jobs\SyncEpgSource;
use App\Models\EpgSource;
use App\Models\Source;
use App\Services\EpgImportService;
use App\Services\EpgService;
use Illuminate\Console\Command;
use Throwable;

class SyncEpgCommand extends Command
{
    protected $signature = 'iptv:sync-epg {--now : Run in this process instead of queueing}';

    protected $description = 'Import the TV guides and match channels to them';

    public function handle(EpgImportService $importer, EpgService $epg): int
    {
        foreach (EpgSource::orderBy('id')->get() as $source) {
            if (!$this->option('now')) {
                if (!$source->isBusy()) {
                    $source->update(['status' => Source::STATUS_QUEUED]);
                    SyncEpgSource::dispatch($source);
                }
                $this->line("Queued: {$source->name}");

                continue;
            }

            try {
                $importer->sync($source);
                $this->info("Imported: {$source->name} ({$source->programme_count} programmes)");
            } catch (Throwable $e) {
                $this->error("Failed: {$source->name}: {$e->getMessage()}");
            }
        }

        if ($this->option('now')) {
            $this->info('Channels with a guide: '.$epg->matchChannels());
        }

        return self::SUCCESS;
    }
}
