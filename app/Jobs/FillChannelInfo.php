<?php

namespace App\Jobs;

use App\Services\ChannelCatalogService;
use App\Services\EpgService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Download the iptv-org channel database when it is older than a week (or when asked), fill in logos and guide ids, then match
 * the TV guide again so the new guide ids count.
 */
class FillChannelInfo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public bool $download = false) {}

    public function handle(ChannelCatalogService $catalog, EpgService $epg): void
    {
        if ($this->download || $catalog->isStale()) {
            $catalog->refresh();
        }

        $catalog->fill();
        $epg->matchChannels();
        $epg->refreshGuidesMissingProgrammes();
    }
}
