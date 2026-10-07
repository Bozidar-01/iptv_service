<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class Category extends Model
{
    public function channels()
    {
        return $this->hasMany(Channel::class);
    }
}
