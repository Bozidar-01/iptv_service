<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'stream_url', 'logo_url', 'category_id', 'is_active', 'source_id', 'external_key', 'tvg_id', 'http_user_agent', 'http_referrer', 'sort_order', 'stream_status', 'stream_checked_at', 'stream_error', 'auto_disabled', 'alert_on_down', 'backup_channel_id', 'using_backup_id'])]
class Channel extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_disabled' => 'boolean',
            'alert_on_down' => 'boolean',
            'stream_checked_at' => 'datetime',
        ];
    }

    /**
     * Channels an admin wants alerts for: watched themselves, or their source is watched.
     */
    public function scopeWatched($query)
    {
        return $query->where(fn ($q) => $q->where('channels.alert_on_down', true)
            ->orWhereIn('channels.source_id', Source::select('id')->where('alert_on_down', true)));
    }

    /**
     * Enabled channels in any of the user's bouquets: what their playlist and the Xtream API list.
     */
    public function scopeAvailableTo($query, User $user)
    {
        return $query->where('channels.is_active', true)
            ->whereHas('bouquets', fn ($q) => $q->whereIn('bouquets.id', $user->bouquets()->select('bouquets.id')));
    }

    /**
     * Playlist order: category name, then position in the source, then name.
     */
    public function scopePlaylistOrder($query)
    {
        return $query->orderBy(Category::select('name')->whereColumn('categories.id', 'channels.category_id'))
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function backupChannel()
    {
        return $this->belongsTo(Channel::class, 'backup_channel_id');
    }

    public function usingBackup()
    {
        return $this->belongsTo(Channel::class, 'using_backup_id');
    }

    /**
     * The channel whose stream is actually played: the backup while the stream check has
     * found ours down and switched to one (StreamCheckService), otherwise this channel.
     */
    public function playbackChannel(): Channel
    {
        $backup = $this->using_backup_id ? $this->usingBackup : null;

        return $backup && $backup->is_active ? $backup : $this;
    }

    public function isWatched(): bool
    {
        return $this->alert_on_down || ($this->source?->alert_on_down ?? false);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    public function bouquets()
    {
        return $this->belongsToMany(Bouquet::class, 'bouquet_channel');
    }
}
