<?php

namespace App\Services;

use App\Models\User;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Two-factor sign-in for admins with an authenticator app (TOTP) and one-time recovery codes.
 * The secret is stored encrypted, recovery codes only as hashes.
 */
class TwoFactorService
{
    public const RECOVERY_CODE_COUNT = 8;

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /**
     * Start setting up: a fresh secret that isn't active until the first code is confirmed.
     */
    public function begin(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();

        return $secret;
    }

    /** The secret of a setup that isn't confirmed yet. */
    public function pendingSecret(User $user): ?string
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null
            ? Crypt::decryptString($user->two_factor_secret)
            : null;
    }

    /**
     * Turn two-factor on when the code from the app is right.
     *
     * @return list<string>|null the recovery codes (shown once), or null when the code is wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        $secret = $this->pendingSecret($user);
        if ($secret === null || ($step = Totp::verify($secret, $code)) === null) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_map([$this, 'hashCode'], $codes))])->save();

        return $codes;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($this->storedHashes($user));
    }

    /**
     * Check a sign-in code: a code from the app (each only once), or a recovery code (used up).
     */
    public function verify(User $user, string $input): bool
    {
        if (!$this->isEnabled($user)) {
            return false;
        }

        $step = Totp::verify(Crypt::decryptString($user->two_factor_secret), $input, $user->two_factor_last_step);
        if ($step !== null) {
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        return $this->useRecoveryCode($user, $input);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** The QR code for the setup page, as an inline SVG. */
    public function qrSvg(User $user, string $secret): string
    {
        $uri = Totp::uri($secret, $user->username, config('app.name'));
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 0), new SvgImageBackEnd()));

        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $writer->writeString($uri));
    }

    private function useRecoveryCode(User $user, string $input): bool
    {
        $input = Str::lower(trim($input));
        if (!preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $input)) {
            return false;
        }

        $hash = $this->hashCode($input);
        $hashes = $this->storedHashes($user);
        $match = null;
        foreach ($hashes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                $match = $index;
            }
        }

        if ($match === null) {
            return false;
        }

        unset($hashes[$match]);
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();

        return true;
    }

    /** @return list<string> */
    private function storedHashes(User $user): array
    {
        return json_decode((string) $user->two_factor_recovery_codes, true) ?: [];
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }
}
