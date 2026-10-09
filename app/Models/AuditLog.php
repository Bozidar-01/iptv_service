<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 *
 * One thing an admin or reseller changed in the panel. Written by AuditService, shown on the Audit log page (admins).
 */
#[Fillable(['actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'subject_name', 'details', 'ip'])]
class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Days the history is kept. */
    public const KEEP_DAYS = 365;

    public const USER_CREATED = 'user.created';

    public const USER_UPDATED = 'user.updated';

    public const USER_DELETED = 'user.deleted';

    public const USER_ENABLED = 'user.enabled';

    public const USER_DISABLED = 'user.disabled';

    public const USER_TRIAL = 'user.trial_created';

    public const USER_RENEWED = 'user.renewed';

    public const USER_LINK = 'user.link_regenerated';

    public const USER_DISCONNECTED = 'user.disconnected';

    public const CREDITS_GIVEN = 'credits.given';

    public const CREDITS_TAKEN = 'credits.taken';

    public const CREDITS_UNLIMITED = 'credits.unlimited';

    public const CHANNELS_HIDDEN = 'channels.hidden';

    public const CHANNELS_SHOWN = 'channels.shown';

    public const SOURCE_DELETED = 'source.deleted';

    public const BOUQUET_DELETED = 'bouquet.deleted';

    public const CHANNELS_DELETED = 'channels.deleted';

    public const CHANNELS_DEDUPLICATED = 'channels.deduplicated';

    public const CLEANUP_APPLIED = 'cleanup.applied';

    public const USERS_BULK = 'users.bulk';

    public const USERS_IMPORTED = 'users.imported';

    public const USERS_EXPORTED = 'users.exported';

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** @return array<string, string> action => label, for the filter menu and the table */
    public static function labels(): array
    {
        return [
            self::USER_CREATED => __('User created'),
            self::USER_UPDATED => __('User changed'),
            self::USER_DELETED => __('User deleted'),
            self::USER_ENABLED => __('User enabled'),
            self::USER_DISABLED => __('User disabled'),
            self::USER_TRIAL => __('Trial created'),
            self::USER_RENEWED => __('User renewed'),
            self::USER_LINK => __('New playlist link'),
            self::USER_DISCONNECTED => __('Devices disconnected'),
            self::CREDITS_GIVEN => __('Credits given'),
            self::CREDITS_TAKEN => __('Credits taken back'),
            self::CREDITS_UNLIMITED => __('Credits made unlimited'),
            self::CHANNELS_HIDDEN => __('Channels hidden'),
            self::CHANNELS_SHOWN => __('Channels shown again'),
            self::SOURCE_DELETED => __('Source deleted'),
            self::BOUQUET_DELETED => __('Bouquet deleted'),
            self::CHANNELS_DELETED => __('Channels deleted'),
            self::CHANNELS_DEDUPLICATED => __('Duplicate channels switched off'),
            self::CLEANUP_APPLIED => __('Cleanup rules applied'),
            self::USERS_BULK => __('Bulk action on users'),
            self::USERS_IMPORTED => __('Users imported'),
            self::USERS_EXPORTED => __('Users exported with passwords'),
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->action] ?? $this->action;
    }

    /** What the entry says, as one line in the panel's language. */
    public function summary(): string
    {
        $d = $this->details ?? [];
        $parts = [];

        // A bulk action on users: what was done and to how many (and the credits it cost)
        if ($this->action === self::USERS_BULK) {
            $what = [
                'enable' => __('enabled'), 'disable' => __('disabled'), 'add_bouquet' => __('bouquet added'),
                'remove_bouquet' => __('bouquet removed'), 'extend' => __('renewed'), 'delete' => __('deleted'),
            ][$d['action'] ?? ''] ?? ($d['action'] ?? '');
            $parts[] = $what.': '.trans_choice(':count user|:count users', $d['count'] ?? 0, ['count' => number_format($d['count'] ?? 0)]);
            if (!empty($d['credits_spent'])) {
                $parts[] = trans_choice(':count credit used|:count credits used', $d['credits_spent'], ['count' => $d['credits_spent']]);
            }

            return implode(' · ', $parts);
        }

        $fields = [
            'username' => __('Username'), 'status' => __('Status'), 'email' => __('Email'), 'locale' => __('Language'),
            'expires' => __('Expires'), 'streams' => __('Streams'), 'role' => __('Role'), 'reseller' => __('Reseller'),
            'max_customers' => __('Most users'), 'max_customer_connections' => __('Most streams per user'),
        ];

        foreach ($d['changes'] ?? [] as $field => [$old, $new]) {
            $parts[] = ($fields[$field] ?? $field).': '.($old ?? '–').' → '.($new ?? '–');
        }
        foreach ($d['set'] ?? [] as $field => $value) {
            $parts[] = ($fields[$field] ?? $field).': '.($value ?? '–');
        }
        if (!empty($d['password'])) {
            $parts[] = __('Password changed');
        }
        if (!empty($d['bouquets_added'])) {
            $parts[] = __('Bouquets added').': '.implode(', ', $d['bouquets_added']);
        }
        if (!empty($d['bouquets_removed'])) {
            $parts[] = __('Bouquets removed').': '.implode(', ', $d['bouquets_removed']);
        }
        if (!empty($d['bouquets'])) {
            $parts[] = __('Bouquets').': '.implode(', ', $d['bouquets']);
        }
        if (isset($d['months'])) {
            $parts[] = trans_choice(':count month|:count months', $d['months'], ['count' => $d['months']]);
        }
        if (!empty($d['credits_spent'])) {
            $parts[] = trans_choice(':count credit used|:count credits used', $d['credits_spent'], ['count' => $d['credits_spent']]);
        }
        if (isset($d['amount'])) {
            $parts[] = trans_choice(':count credit|:count credits', abs($d['amount']), ['count' => abs($d['amount'])]).(!empty($d['note']) ? ' ('.$d['note'].')' : '');
        }
        if (isset($d['count'])) {
            $parts[] = trans_choice(':count channel|:count channels', $d['count'], ['count' => number_format($d['count'])])
                .(isset($d['for']) ? ' · '.$d['for'] : '');
        }
        if (in_array($this->action, [self::USERS_IMPORTED, self::USERS_EXPORTED], true)) {
            $parts[] = trans_choice(':count user|:count users', $d['count'] ?? 0, ['count' => number_format($d['count'] ?? 0)]);
            if (!empty($d['credits_spent'])) {
                $parts[] = trans_choice(':count credit used|:count credits used', $d['credits_spent'], ['count' => $d['credits_spent']]);
            }

            return implode(' · ', $parts);
        }
        if (isset($d['names'])) {
            $parts[] = __(':names names and :categories categories changed.', ['names' => number_format($d['names']), 'categories' => number_format($d['categories'] ?? 0)]);
        }
        if (isset($d['groups'])) {
            $parts[] = trans_choice(':count group|:count groups', $d['groups'], ['count' => number_format($d['groups'])]);
        }
        if (isset($d['devices'])) {
            $parts[] = trans_choice(':count device|:count devices', $d['devices'], ['count' => $d['devices']]);
        }

        return implode(' · ', $parts);
    }
}
