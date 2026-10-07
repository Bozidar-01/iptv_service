<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['epg_source_id', 'xmltv_id', 'name', 'name_key'])]
class EpgChannel extends Model
{
    public $timestamps = false;
}
