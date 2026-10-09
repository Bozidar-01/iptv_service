<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A manual launch-checklist item an admin has ticked off.
 */
#[Fillable(['item', 'user_id'])]
class ChecklistTick extends Model {}
