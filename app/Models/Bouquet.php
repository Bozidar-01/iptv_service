<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class Bouquet extends Model
{
    public function channels()
    {
        return $this->belongsToMany(Channel::class, 'bouquet_channel');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_bouquet');
    }
}
