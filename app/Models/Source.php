<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'type', 'url', 'file_path', 'bouquet_id', 'skip_vod', 'auto_sync', 'status', 'last_error', 'channel_count', 'last_synced_at'])]
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
            'channel_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function channels()
    {
        return $this->hasMany(Channel::class);
    }

    public function bouquet()
    {
        return $this->belongsTo(Bouquet::class);
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
