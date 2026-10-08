<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 6 digits, 30 seconds): what Google
 * Authenticator, Microsoft Authenticator, Authy, 1Password and others generate.
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret (160 bits) in base32, the form authenticator apps take. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code for a time step (step = unix time / 30). */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0xf;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * The time step the code belongs to, or null when it is wrong. One step either side is
     * accepted for clock drift. Steps up to $after are refused so a code works only once.
     */
    public static function verify(string $secret, string $input, ?int $after = null, ?int $time = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input);
        if (!preg_match('/^\d{'.self::DIGITS.'}$/', $input)) {
            return null;
        }

        $now = self::step($time);
        $found = null;
        foreach ([$now - 1, $now, $now + 1] as $step) {
            // No early exit: every candidate is compared, so timing doesn't tell which one matched
            if (hash_equals(self::code($secret, $step), $input) && ($after === null || $step > $after)) {
                $found = $step;
            }
        }

        return $found;
    }

    /** The otpauth:// address that authenticator apps read from a QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value !== false) {
                $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
            }
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
