<?php

namespace App\Services;

use App\Models\Bouquet;
use App\Models\Channel;

class BouquetService
{
    public function getAllBouquets()
    {
        return Bouquet::withCount([
            'channels',
            'channels as active_channels_count' => fn ($q) => $q->where('is_active', true),
            'users',
        ])->orderBy('name')->get();
    }

    public function createBouquet(array $data)
    {
        $bouquet = Bouquet::create($data);
        $this->addCategories($bouquet, $data['categories'] ?? []);

        return $bouquet;
    }

    public function updateBouquet(Bouquet $bouquet, array $data)
    {
        $bouquet->update($data);
        $this->addCategories($bouquet, $data['categories'] ?? []);

        return $bouquet;
    }

    public function deleteBouquet(Bouquet $bouquet)
    {
        return $bouquet->delete();
    }

    /**
     * Add every channel in the given categories to the bouquet (existing channels are kept).
     */
    public function addCategories(Bouquet $bouquet, array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        ChannelService::attachToBouquet($bouquet->id, Channel::whereIn('category_id', $categoryIds));
    }
}
