<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChannelService
{
    public const BULK_ACTIONS = ['enable', 'disable', 'add_to_bouquet', 'remove_from_bouquet', 'alerts_on', 'alerts_off', 'delete'];

    /**
     * Channels matching the admin list filters.
     *
     * Filters: search, category (id or "none"), source (id or "manual"), bouquet (id),
     * status ("active"|"inactive"|"offline" = switched off by the stream check|"watched" = alerts on|"backup" = playing a backup).
     */
    public function filteredQuery(array $filters): Builder
    {
        return Channel::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('channels.name', 'like', '%'.$search.'%'))
            ->when($filters['category'] ?? null, fn ($q, $category) => $category === 'none'
                ? $q->whereNull('category_id')
                : $q->where('category_id', $category))
            ->when($filters['source'] ?? null, fn ($q, $source) => $source === 'manual'
                ? $q->whereNull('source_id')
                : $q->where('source_id', $source))
            ->when($filters['bouquet'] ?? null, fn ($q, $bouquet) => $q->whereHas('bouquets', fn ($b) => $b->where('bouquets.id', $bouquet)))
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'offline' => $q->where('auto_disabled', true),
                'watched' => $q->watched(),
                'backup' => $q->whereNotNull('using_backup_id'),
                default => $q->where('is_active', $status === 'active'),
            });
    }

    public function getAllChannels(array $filters = [])
    {
        return $this->filteredQuery($filters)
            ->with(['category', 'source', 'usingBackup:id,name'])
            ->withCount('bouquets')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * Apply a bulk action to every channel in the query.
     *
     * @return int number of channels affected
     */
    public function bulkAction(string $action, Builder $query, ?int $bouquetId = null): int
    {
        // Enabling or disabling by hand takes the channel out of the stream check's control
        // Wrapped in a derived table: MySQL can't UPDATE/DELETE channels with a subquery on channels
        $ids = DB::query()->fromSub((clone $query)->select('channels.id'), 'matched')->select('id');

        return match ($action) {
            'enable' => Channel::whereIn('id', $ids)->update(['is_active' => true, 'auto_disabled' => false]),
            'disable' => Channel::whereIn('id', $ids)->update(['is_active' => false, 'auto_disabled' => false]),
            'alerts_on' => Channel::whereIn('id', $ids)->update(['alert_on_down' => true]),
            'alerts_off' => Channel::whereIn('id', $ids)->update(['alert_on_down' => false]),
            'delete' => Channel::whereIn('id', $ids)->delete(),
            'add_to_bouquet' => self::attachToBouquet($bouquetId, Channel::whereIn('id', $ids)),
            'remove_from_bouquet' => DB::table('bouquet_channel')
                ->where('bouquet_id', $bouquetId)
                ->whereIn('channel_id', $ids)
                ->delete(),
            default => throw new \InvalidArgumentException("Unknown bulk action: {$action}"),
        };
    }

    /**
     * Attach every channel matched by the query to the bouquet, skipping ones already in it.
     * Set-based so it scales to provider lists with tens of thousands of channels.
     *
     * @return int number of channels added
     */
    public static function attachToBouquet(int $bouquetId, Builder $channels): int
    {
        return DB::table('bouquet_channel')->insertUsing(
            ['bouquet_id', 'channel_id'],
            (clone $channels)
                ->selectRaw('? as bouquet_id, channels.id', [$bouquetId])
                ->whereNotIn('channels.id', DB::table('bouquet_channel')->select('channel_id')->where('bouquet_id', $bouquetId))
                ->toBase()
        );
    }

    public function getAllCategories()
    {
        return Category::orderBy('name')->get();
    }

    public function getCategoriesWithCounts()
    {
        return Category::withCount([
            'channels',
            'channels as active_channels_count' => fn ($q) => $q->where('is_active', true),
        ])->orderBy('name')->get();
    }

    /**
     * Channels that probably show the same programme, offered as backups on the edit form:
     * same tvg-id or same name, from any source.
     */
    public function backupSuggestions(Channel $channel)
    {
        return Channel::with('source:id,name')
            ->whereKeyNot($channel->id)
            ->where(fn ($q) => $q->where('name', $channel->name)
                ->when($channel->tvg_id, fn ($q) => $q->orWhere('tvg_id', $channel->tvg_id))
                ->when($channel->backup_channel_id, fn ($q) => $q->orWhere('id', $channel->backup_channel_id)))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'source_id', 'is_active', 'stream_status']);
    }

    public function createChannel(array $data)
    {
        return Channel::create($data);
    }

    public function updateChannel(Channel $channel, array $data)
    {
        if (array_key_exists('is_active', $data) && $data['is_active'] !== $channel->is_active) {
            $data['auto_disabled'] = false;
        }

        return $channel->update($data);
    }

    public function deleteChannel(Channel $channel)
    {
        return $channel->delete();
    }

    public function createCategory(array $data)
    {
        return Category::create($data);
    }

    public function updateCategory(Category $category, array $data)
    {
        return $category->update($data);
    }

    public function deleteCategory(Category $category)
    {
        return $category->delete();
    }
}
