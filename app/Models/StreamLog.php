<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'channel_id', 'ip', 'user_agent', 'started_at'])]
class StreamLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
        ];
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
