<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Months of subscription sold to a user: a new subscription or a renewal (SaleService). The seller is the user's reseller, or
 * the admins (owner_id null). The amount is the seller's price per month at the time; it is paid when paid_at is set.
 *
 * @property Carbon|null $paid_at
 */
#[Fillable(['owner_id', 'customer_id', 'customer_name', 'kind', 'months', 'amount', 'paid_at', 'created_by'])]
class Sale extends Model
{
    public const NEW = 'new';

    public const RENEWAL = 'renewal';

    protected function casts(): array
    {
        return ['months' => 'integer', 'amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
