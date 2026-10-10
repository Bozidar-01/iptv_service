<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A play the panel refused, and why (StreamService::denialReason). Shown on Activity → Refused and on the user page.
 *
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'channel_id', 'reason', 'ip', 'user_agent'])]
class DeniedPlay extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const DISABLED = 'disabled';

    public const EXPIRED = 'expired';

    public const BLOCKED = 'device_blocked';

    public const CHANNEL_OFF = 'channel_off';

    public const HIDDEN = 'hidden';

    public const NOT_IN_BOUQUET = 'not_in_bouquet';

    public const LIMIT = 'connection_limit';

    public const REASONS = [self::DISABLED, self::EXPIRED, self::BLOCKED, self::CHANNEL_OFF, self::HIDDEN, self::NOT_IN_BOUQUET, self::LIMIT];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('iptv.log_retention_days')));
    }

    /** @return array<string, string> reason => short label */
    public static function labels(): array
    {
        return [
            self::DISABLED => __('Account disabled'),
            self::EXPIRED => __('Subscription ended'),
            self::BLOCKED => __('Device disconnected'),
            self::CHANNEL_OFF => __('Channel switched off'),
            self::HIDDEN => __('Channel hidden for this user'),
            self::NOT_IN_BOUQUET => __('Channel not in their bouquets'),
            self::LIMIT => __('Too many devices at once'),
        ];
    }

    /** @return array<string, string> reason => what to do about it */
    public static function advice(): array
    {
        return [
            self::DISABLED => __('Enable the user.'),
            self::EXPIRED => __('Renew the user.'),
            self::BLOCKED => __('An admin disconnected this device; it can start again after a few minutes.'),
            self::CHANNEL_OFF => __('Enable the channel (it may have been switched off by hand, by the stream check or by Find duplicates).'),
            self::HIDDEN => __('Show the channel again for this user (Choose channels on their page, or Hide channels).'),
            self::NOT_IN_BOUQUET => __('Give the user a bouquet that has this channel.'),
            self::LIMIT => __('Close the other player, wait 5 minutes, disconnect the old device on Live, or raise Streams at once.'),
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->reason] ?? $this->reason;
    }

    public function hint(): string
    {
        return self::advice()[$this->reason] ?? '';
    }
}
