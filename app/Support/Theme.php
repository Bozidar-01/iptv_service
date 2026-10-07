<?php

namespace App\Support;

/**
 * Colour theme chosen in the panel (the "theme" cookie). "system" follows the OS setting.
 * The cookie is left unencrypted (bootstrap/app.php) so the switcher's JS can set it instantly.
 */
class Theme
{
    public const COOKIE = 'theme';

    public const OPTIONS = ['light', 'dark', 'system'];

    public static function current(): string
    {
        $theme = request()->cookie(self::COOKIE);

        return in_array($theme, self::OPTIONS, true) ? $theme : 'system';
    }
}
