<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['username', 'password', 'status', 'role', 'expiration_date', 'max_connections'])]
#[Hidden(['password', 'remember_token', 'playlist_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
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
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function bouquets()
    {
        return $this->belongsToMany(Bouquet::class, 'user_bouquet');
    }

    public function activeSessions()
    {
        return $this->hasMany(ActiveSession::class);
    }

    public function streamLogs()
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

    public function accessTokens()
    {
        return $this->hasMany(AccessToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
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
