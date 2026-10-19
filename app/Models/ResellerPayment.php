<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money a reseller paid the admins for credits (SaleService::resellerBalance).
 *
 * @property Carbon $paid_on
 */
#[Fillable(['reseller_id', 'amount', 'note', 'paid_on', 'recorded_by'])]
class ResellerPayment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }
}
