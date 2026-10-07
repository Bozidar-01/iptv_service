<?php

namespace App\Services;

use App\Models\Bouquet;

class BouquetService
{
    public function getAllBouquets()
    {
        return Bouquet::withCount('channels', 'users')->get();
    }

    public function createBouquet(array $data)
    {
        $bouquet = Bouquet::create($data);
        if (isset($data['channels'])) {
            $bouquet->channels()->sync($data['channels']);
        }
        return $bouquet;
    }

    public function updateBouquet(Bouquet $bouquet, array $data)
    {
        $bouquet->update($data);
        if (isset($data['channels'])) {
            $bouquet->channels()->sync($data['channels']);
        }
        return $bouquet;
    }

    public function deleteBouquet(Bouquet $bouquet)
    {
        return $bouquet->delete();
    }
}
