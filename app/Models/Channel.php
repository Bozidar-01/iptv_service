<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'stream_url', 'logo_url', 'category_id', 'is_active'])]
class Channel extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function bouquets()
    {
        return $this->belongsToMany(Bouquet::class, 'bouquet_channel');
    }
}
