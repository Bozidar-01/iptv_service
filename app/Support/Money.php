<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Amounts of money in the panel's currency (Payments page, `money` setting).
 */
final class Money
{
    public const DEFAULT_CURRENCY = 'EUR';

    public static function currency(): string
    {
        $currency = ((array) AppSetting::get('money', []))['currency'] ?? null;

        return is_string($currency) && $currency !== '' ? $currency : self::DEFAULT_CURRENCY;
    }

    /** "12.50 EUR"; "–" for null. */
    public static function format(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '–';
        }

        return number_format((float) $amount, 2).' '.self::currency();
    }
}
