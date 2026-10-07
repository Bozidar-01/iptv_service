<?php

namespace App\Models;

use App\Services\StreamCheckService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something an admin asked to be told about: a watched channel stopped or started working,
 * a watched source failed to import, or background jobs stopped running. Shared by all admins.
 */
#[Fillable(['type', 'channel_id', 'source_id', 'name', 'detail', 'read_at', 'mailed_at'])]
class StreamAlert extends Model
{
    public const TYPE_DOWN = 'down';
    public const TYPE_UP = 'up';
    public const TYPE_SYNC_FAILED = 'sync_failed';
    /** Queued jobs waiting too long: the queue worker isn't running. detail = minutes waited */
    public const TYPE_QUEUE_STUCK = 'queue_stuck';
    /** Background jobs failed. detail = how many */
    public const TYPE_JOBS_FAILED = 'jobs_failed';

    /** A satellite receiver's relay stopped / started answering (ReceiverService). */
    public const TYPE_RECEIVER_DOWN = 'receiver_down';
    public const TYPE_RECEIVER_UP = 'receiver_up';

    public const SYSTEM_TYPES = [self::TYPE_QUEUE_STUCK, self::TYPE_JOBS_FAILED, self::TYPE_RECEIVER_DOWN];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'mailed_at' => 'datetime',
        ];
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function message(): string
    {
        return match ($this->type) {
            self::TYPE_DOWN => $this->detail
                ? __(':name stopped working: :reason', ['name' => $this->name, 'reason' => $this->reason()])
                : __(':name stopped working', ['name' => $this->name]),
            self::TYPE_UP => __(':name is working again', ['name' => $this->name]),
            self::TYPE_SYNC_FAILED => __('Import of :name failed', ['name' => $this->name]),
            self::TYPE_QUEUE_STUCK => __('Background jobs are not running: the oldest has waited :minutes minutes', ['minutes' => (int) $this->detail]),
            self::TYPE_RECEIVER_DOWN => __('Receiver :name is offline', ['name' => $this->name]),
            self::TYPE_RECEIVER_UP => __('Receiver :name is back online', ['name' => $this->name]),
            self::TYPE_JOBS_FAILED => trans_choice(':count background job failed|:count background jobs failed', (int) $this->detail, ['count' => (int) $this->detail]),
            default => $this->name,
        };
    }

    /**
     * Readable reason a channel stopped working (from the stream check), if known.
     */
    public function reason(): ?string
    {
        return $this->type === self::TYPE_DOWN ? StreamCheckService::describeError($this->detail) : null;
    }

    public function isProblem(): bool
    {
        return !in_array($this->type, [self::TYPE_UP, self::TYPE_RECEIVER_UP], true);
    }

    /**
     * Short text for lists that already say what happened (email groups): the channel or
     * source name, or the full message for alerts that aren't about one channel.
     */
    public function title(): string
    {
        return in_array($this->type, [self::TYPE_DOWN, self::TYPE_UP, self::TYPE_SYNC_FAILED], true) ? $this->name : $this->message();
    }

    public function isSystem(): bool
    {
        return in_array($this->type, self::SYSTEM_TYPES, true);
    }
}
