<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChannelService
{
    public const BULK_ACTIONS = ['enable', 'disable', 'add_to_bouquet', 'remove_from_bouquet', 'delete'];

    /**
     * Channels matching the admin list filters.
     *
     * Filters: search, category (id or "none"), source (id or "manual"), bouquet (id), status ("active"|"inactive").
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
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'));
    }

    public function getAllChannels(array $filters = [])
    {
        return $this->filteredQuery($filters)
            ->with(['category', 'source'])
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
        // Wrapped in a derived table: MySQL can't UPDATE/DELETE channels with a subquery on channels
        $ids = DB::query()->fromSub((clone $query)->select('channels.id'), 'matched')->select('id');

        return match ($action) {
            'enable' => Channel::whereIn('id', $ids)->update(['is_active' => true]),
            'disable' => Channel::whereIn('id', $ids)->update(['is_active' => false]),
            'delete' => Channel::whereIn('id', $ids)->delete(),
            'add_to_bouquet' => self::attachToBouquet($bouquetId, Channel::whereIn('id', $ids)),
            'remove_from_bouquet' => DB::table('bouquet_channel')
                ->where('bouquet_id', $bouquetId)
                ->whereIn('channel_id', $ids)
                ->delete(),
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

    public function createChannel(array $data)
    {
        return Channel::create($data);
    }

    public function updateChannel(Channel $channel, array $data)
    {
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
