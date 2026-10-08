<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'stream_url', 'logo_url', 'category_id', 'is_active', 'source_id', 'external_key', 'tvg_id', 'epg_source_id', 'epg_id', 'epg_manual', 'http_user_agent', 'http_referrer', 'sort_order', 'stream_status', 'stream_checked_at', 'stream_error', 'auto_disabled', 'alert_on_down', 'backup_channel_id', 'using_backup_id'])]
class Channel extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_disabled' => 'boolean',
            'alert_on_down' => 'boolean',
            'epg_manual' => 'boolean',
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
            ->orderBy('name')
            // Last, so channels that tie on everything keep one fixed order: chunked reads (lazy) page by OFFSET
            ->orderBy('channels.id');
    }

    /** @return BelongsTo<EpgSource, $this> */
    public function epgSource(): BelongsTo
    {
        return $this->belongsTo(EpgSource::class);
    }

    /**
     * Guide ID used in playlists and the Xtream API: the matched XMLTV id, else the playlist's tvg-id.
     */
    public function guideId(): ?string
    {
        return $this->epg_id ?: $this->tvg_id;
    }

    /** @return BelongsTo<Channel, $this> */
    public function backupChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'backup_channel_id');
    }

    /** @return BelongsTo<Channel, $this> */
    public function usingBackup(): BelongsTo
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

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsToMany<Bouquet, $this> */
    public function bouquets(): BelongsToMany
    {
        return $this->belongsToMany(Bouquet::class, 'bouquet_channel');
    }
}
