<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An XMLTV guide file (e.g. https://iptv-epg.org/files/epg-mk.xml.gz), imported by EpgImportService.
 */
#[Fillable(['name', 'url', 'status', 'last_error', 'channel_count', 'programme_count', 'last_synced_at'])]
class EpgSource extends Model
{
    protected function casts(): array
    {
        return [
            'channel_count' => 'integer',
            'programme_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function epgChannels()
    {
        return $this->hasMany(EpgChannel::class);
    }

    public function programmes()
    {
        return $this->hasMany(EpgProgramme::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [Source::STATUS_QUEUED, Source::STATUS_SYNCING], true);
    }
}
