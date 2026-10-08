<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'channel_id', 'ip', 'user_agent', 'started_at'])]
class StreamLog extends Model
{
    use Prunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
        ];
    }

    /**
     * Old plays are deleted by `php artisan model:prune` (scheduled in routes/console.php).
     */
    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subDays(config('iptv.log_retention_days')));
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
