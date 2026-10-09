<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $expiration_date
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $created_at
 * @property int|null $credits
 */
#[Fillable(['username', 'password', 'password_plain', 'email', 'locale', 'status', 'role', 'expiration_date', 'max_connections', 'owner_id', 'max_customers', 'max_customer_connections'])]
#[Hidden(['password', 'password_plain', 'remember_token', 'playlist_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->playlist_token ??= Str::random(32);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiration_date' => 'datetime',
            'password' => 'hashed',
            'password_plain' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'credits' => 'integer',
        ];
    }

    /** @return BelongsToMany<Bouquet, $this> */
    public function bouquets(): BelongsToMany
    {
        return $this->belongsToMany(Bouquet::class, 'user_bouquet');
    }

    /** @return HasMany<ActiveSession, $this> */
    public function activeSessions(): HasMany
    {
        return $this->hasMany(ActiveSession::class);
    }

    /** @return HasMany<StreamLog, $this> */
    public function streamLogs(): HasMany
    {
        return $this->hasMany(StreamLog::class);
    }

    /**
     * Invalidate the old playlist link by issuing a new token.
     */
    public function regeneratePlaylistToken(): void
    {
        $this->playlist_token = Str::random(32);
        $this->save();
    }

    /** @return HasMany<AccessToken, $this> */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(AccessToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Signs in to the panel, but only sees and manages their own customers. */
    public function isReseller(): bool
    {
        return $this->role === 'reseller';
    }

    /** Admins and resellers use the panel. */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isReseller();
    }

    /**
     * Can this person sign in to the panel right now? Admins always; a reseller only while their own
     * account is active and not expired (so an admin can switch a reseller off).
     */
    public function canUsePanel(): bool
    {
        return $this->isAdmin() || ($this->isReseller() && $this->isActive());
    }

    /** @return BelongsTo<User, $this> the reseller this subscriber belongs to */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<User, $this> a reseller's subscribers */
    public function customers(): HasMany
    {
        return $this->hasMany(User::class, 'owner_id');
    }

    /**
     * The users $actor may see and manage: everyone for an admin, only their own customers for a reseller,
     * nobody for anyone else (also for no one signed in).
     */
    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        return match (true) {
            $actor?->isAdmin() => $query,
            $actor?->isReseller() => $query->where('owner_id', $actor->id)->where('role', 'user'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Is this reseller limited by credits? (no balance = unlimited, as before credits existed) */
    public function usesCredits(): bool
    {
        return $this->isReseller() && $this->credits !== null;
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** Can a reseller still add a customer? (admins and resellers without a limit: always) */
    public function canAddCustomer(): bool
    {
        return $this->max_customers === null || $this->customers()->count() < $this->max_customers;
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expiration_date !== null && $this->expiration_date->isPast();
    }
}
