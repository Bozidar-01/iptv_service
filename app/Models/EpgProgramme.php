<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $start
 * @property Carbon $stop
 */
#[Fillable(['epg_source_id', 'xmltv_id', 'start', 'stop', 'title', 'description'])]
class EpgProgramme extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'start' => 'datetime',
            'stop' => 'datetime',
        ];
    }
}
