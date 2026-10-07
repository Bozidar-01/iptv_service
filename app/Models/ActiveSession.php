<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Services\StreamService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable(['user_id', 'channel_id', 'ip', 'user_agent', 'started_at', 'last_activity_at'])]
class ActiveSession extends Model
{
    use Prunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Stale sessions are deleted by `php artisan model:prune` (scheduled in routes/console.php).
     */
    public function prunable(): Builder
    {
        return static::where('last_activity_at', '<=', now()->subMinutes(StreamService::SESSION_TIMEOUT_MINUTES));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}
