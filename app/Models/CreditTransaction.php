<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a reseller's credit balance (CreditService). Rows are only ever added.
 */
#[Fillable(['user_id', 'actor_id', 'customer_id', 'amount', 'balance_after', 'reason', 'note'])]
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    public const GIVEN = 'admin_grant';

    public const TAKEN = 'admin_take';

    public const UNLIMITED = 'admin_unlimited';

    public const NEW_SUBSCRIPTION = 'new_subscription';

    public const EXTENDED = 'extended_subscription';

    /** @return BelongsTo<User, $this> the reseller */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> the user the credits were spent on */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function label(): string
    {
        return match ($this->reason) {
            self::GIVEN => __('Credits given'),
            self::TAKEN => __('Credits taken back'),
            self::UNLIMITED => __('Set to unlimited'),
            self::NEW_SUBSCRIPTION => __('New subscription'),
            self::EXTENDED => __('Subscription extended'),
            default => $this->reason,
        };
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'amount' => 'integer', 'balance_after' => 'integer'];
    }
}
