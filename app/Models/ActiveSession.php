<?php

namespace App\Models;

use App\Services\StreamService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'channel_id', 'ip', 'user_agent', 'started_at', 'last_activity_at'])]
class ActiveSession extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** Sessions of devices that played something in the last SESSION_TIMEOUT_MINUTES. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('last_activity_at', '>', now()->subMinutes(StreamService::SESSION_TIMEOUT_MINUTES));
    }

    /**
     * Stale sessions are deleted by `php artisan model:prune` (scheduled in routes/console.php).
     */
    public function prunable(): Builder
    {
        return static::where('last_activity_at', '<=', now()->subMinutes(StreamService::SESSION_TIMEOUT_MINUTES));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
