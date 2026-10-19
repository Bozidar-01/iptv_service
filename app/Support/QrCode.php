<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code as inline SVG, made on the server (no script on the page): playlist links and viewers' pages, scanned with a phone
 * instead of typed.
 */
final class QrCode
{
    public static function svg(string $text, int $size = 168): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $writer->writeString($text));
    }
}
