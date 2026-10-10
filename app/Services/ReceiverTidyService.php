<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shorten a satellite receiver's huge channel list. The stream check can't test receiver channels (it would take a tuner), so
 * this works from what the service reference and the guide say: radio and data services, internal stream references, and channels
 * without a TV guide. Switching off never deletes: a disabled channel stays disabled through every sync, and can be enabled again.
 */
class ReceiverTidyService
{
    public const CRITERIA = ['radio', 'internal', 'no_guide'];

    /** Enigma2 service types (hex, third field of the reference) that are not video: radio and data. */
    private const NON_VIDEO_TYPES = ['2', '0A', 'A', '3', '4', '5', '6', '7', '8', '9', 'B', 'C', 'D', 'E', 'F'];

    /** Enabled channels of the source that match a criterion. */
    public function matching(Source $source, string $criterion): Builder
    {
        $query = Channel::where('source_id', $source->id)->where('is_active', true);

        return match ($criterion) {
            'radio' => $query->where(fn ($q) => $q->where('name', 'like', '(RADIO)%')->orWhere(function ($q) {
                foreach (self::NON_VIDEO_TYPES as $type) {
                    $q->orWhere('tvg_id', 'like', '1:0:'.$type.':%');
                }
            })),
            'internal' => $query->where('tvg_id', 'like', '%http%3a%'),
            'no_guide' => $query->whereNull('epg_source_id'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** @return array<string, int> criterion => how many enabled channels, and the union as `any` for the chosen ones */
    public function counts(Source $source): array
    {
        $counts = [];
        foreach (self::CRITERIA as $criterion) {
            $counts[$criterion] = $this->matching($source, $criterion)->count();
        }

        return $counts + ['enabled' => Channel::where('source_id', $source->id)->where('is_active', true)->count()];
    }

    /**
     * Switch off every enabled channel matching any of the chosen criteria.
     *
     * @param  list<string>  $criteria
     * @return int channels switched off
     */
    public function apply(Source $source, array $criteria): int
    {
        $criteria = array_values(array_intersect($criteria, self::CRITERIA));
        if ($criteria === []) {
            return 0;
        }

        $query = Channel::where('source_id', $source->id)->where('is_active', true)->where(function ($q) use ($source, $criteria) {
            foreach ($criteria as $criterion) {
                $q->orWhereIn('channels.id', $this->matching($source, $criterion)->select('channels.id'));
            }
        });
        // MySQL can't update a table while selecting from it: use a derived table
        $ids = DB::query()->fromSub($query->select('channels.id')->toBase(), 'matched')->select('id');

        return Channel::whereIn('id', $ids)->update(['is_active' => false, 'auto_disabled' => false]);
    }
}
