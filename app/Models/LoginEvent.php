<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Http\Request;

/**
 * One panel sign-in attempt (AuthController), shown on the Security page.
 */
#[Fillable(['user_id', 'username', 'event', 'ip', 'user_agent'])]
class LoginEvent extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const DENIED = 'denied';
    public const TWO_FACTOR_FAILED = 'two_factor_failed';
    public const TWO_FACTOR_ENABLED = 'two_factor_enabled';
    public const TWO_FACTOR_DISABLED = 'two_factor_disabled';

    /** Days the history is kept. */
    public const KEEP_DAYS = 180;

    public static function record(string $event, string $username, ?User $user, Request $request): self
    {
        return self::create([
            'user_id' => $user?->id,
            'username' => mb_substr($username, 0, 255),
            'event' => $event,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function label(): string
    {
        return match ($this->event) {
            self::SUCCESS => __('Signed in'),
            self::FAILED => __('Wrong password'),
            self::DENIED => __('Not an administrator'),
            self::TWO_FACTOR_FAILED => __('Wrong code'),
            self::TWO_FACTOR_ENABLED => __('Two-factor sign-in turned on'),
            self::TWO_FACTOR_DISABLED => __('Two-factor sign-in turned off'),
            default => $this->event,
        };
    }

    public function isProblem(): bool
    {
        return in_array($this->event, [self::FAILED, self::DENIED, self::TWO_FACTOR_FAILED], true);
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
