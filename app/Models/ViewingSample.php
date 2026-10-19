<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * How many streams were playing at one moment, per source (null = channels added by hand). Taken every 5 minutes
 * (iptv:sample-viewing) for the "most at once" numbers on the Statistics page.
 */
#[Fillable(['taken_at', 'source_id', 'streams'])]
class ViewingSample extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'streams' => 'integer'];
    }

    /** Kept as long as the play history. */
    public function prunable(): Builder
    {
        return static::where('taken_at', '<', now()->subDays(config('iptv.log_retention_days')));
    }
}
