<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $receiver_checked_at
 */
#[Hidden(['relay_secret'])]
#[Fillable(['name', 'type', 'url', 'file_path', 'relay_secret', 'transcode', 'receiver_online', 'receiver_checked_at', 'bouquet_id', 'skip_vod', 'auto_sync', 'check_streams', 'proxy_streams', 'alert_on_down', 'status', 'last_error', 'channel_count', 'last_synced_at'])]
class Source extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * Hours between automatic re-syncs for sources with auto_sync enabled.
     */
    public const AUTO_SYNC_HOURS = 24;

    protected function casts(): array
    {
        return [
            'skip_vod' => 'boolean',
            'auto_sync' => 'boolean',
            'check_streams' => 'boolean',
            'proxy_streams' => 'boolean',
            // Provider addresses contain the provider login
            'url' => 'encrypted',
            'relay_secret' => 'encrypted',
            'transcode' => 'boolean',
            'receiver_online' => 'boolean',
            'receiver_checked_at' => 'datetime',
            'alert_on_down' => 'boolean',
            'channel_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<Channel, $this> */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /** @return BelongsTo<Bouquet, $this> */
    public function bouquet(): BelongsTo
    {
        return $this->belongsTo(Bouquet::class);
    }

    /**
     * A satellite receiver behind a NeboTV relay (relay/nebotv-relay.py): channels play
     * through signed relay links (ReceiverService).
     */
    public function isReceiver(): bool
    {
        return $this->type === 'receiver';
    }

    /**
     * Address the importer downloads: the URL itself, or the relay's protected playlist.
     */
    public function playlistUrl(): ?string
    {
        return $this->isReceiver()
            ? rtrim((string) $this->url, '/').'/playlist.m3u?key='.urlencode((string) $this->relay_secret)
            : $this->url;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_SYNCING], true);
    }

    public function isDueForSync(): bool
    {
        return $this->auto_sync
            && !$this->isBusy()
            && ($this->last_synced_at === null || $this->last_synced_at->lte(now()->subHours(self::AUTO_SYNC_HOURS)));
    }
}
