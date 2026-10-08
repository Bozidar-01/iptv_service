<?php

namespace App\Console\Commands;

use App\Jobs\SyncSource;
use App\Models\Source;
use App\Services\SourceSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncSources extends Command
{
    protected $signature = 'iptv:sync-sources
                            {source? : ID of a single source to sync}
                            {--due : Only queue sources whose auto-sync is due}
                            {--now : Run in this process instead of queueing}';

    protected $description = 'Import or refresh channels from M3U sources';

    public function handle(SourceSyncService $syncService): int
    {
        $sources = $this->argument('source')
            ? Source::whereKey($this->argument('source'))->get()
            : Source::all();

        if ($this->option('due')) {
            $sources = $sources->filter->isDueForSync();
        }

        if ($sources->isEmpty()) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            if (!$this->option('now')) {
                $source->update(['status' => Source::STATUS_QUEUED]);
                SyncSource::dispatch($source);
                $this->line("Queued: {$source->name}");

                continue;
            }

            try {
                $syncService->sync($source);
                $this->info("Synced: {$source->name} ({$source->channel_count} channels)");
            } catch (Throwable $e) {
                $this->error("Failed: {$source->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
