<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Bouquet extends Model
{
    /** @return BelongsToMany<Channel, $this> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'bouquet_channel');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_bouquet');
    }
}
