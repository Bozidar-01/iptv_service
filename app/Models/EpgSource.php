<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return HasMany<EpgChannel, $this> */
    public function epgChannels(): HasMany
    {
        return $this->hasMany(EpgChannel::class);
    }

    /** @return HasMany<EpgProgramme, $this> */
    public function programmes(): HasMany
    {
        return $this->hasMany(EpgProgramme::class);
    }

    /** The country of a per-country guide file (https://iptv-epg.org/files/epg-de.xml.gz → "de"), else null. */
    public function country(): ?string
    {
        return preg_match('~/epg-([a-z]{2})\.xml(?:\.gz)?$~i', (string) parse_url($this->url, PHP_URL_PATH), $m) ? strtolower($m[1]) : null;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [Source::STATUS_QUEUED, Source::STATUS_SYNCING], true);
    }
}
