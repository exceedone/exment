<?php

namespace Exceedone\Exment\Services\Line;

use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Render text (deep link LINE) thành ảnh QR SVG.
 * Dùng simplesoftwareio/simple-qrcode (đã có trong project).
 */
class QrRenderer
{
    public static function svg(string $text, int $size = 256): string
    {
        return (string) QrCode::format('svg')->size($size)->margin(1)->generate($text);
    }

    public static function svgDataUri(string $text, int $size = 256): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($text, $size));
    }
}
