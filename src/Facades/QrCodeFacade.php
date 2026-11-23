<?php

namespace Exceedone\Exment\Facades;

use Exceedone\Exment\Services\QrCode\QrCodeFactory;
use Illuminate\Support\Facades\Facade;

/**
 * QrCode::format('png')->size(200)->generate($text)
 *
 * Registered as the "QrCode" alias, the name simplesoftwareio/simple-qrcode used (#1902).
 *
 * @method static \Exceedone\Exment\Services\QrCode\QrCodeBuilder format(string $format)
 * @method static \Exceedone\Exment\Services\QrCode\QrCodeBuilder size(int $size)
 * @method static \Exceedone\Exment\Services\QrCode\QrCodeBuilder margin(int $margin)
 * @method static \Exceedone\Exment\Services\QrCode\QrCodeBuilder errorCorrection(string $level)
 * @method static \Exceedone\Exment\Services\QrCode\QrCodeBuilder encoding(string $encoding)
 * @method static string|null generate(string $text, ?string $filename = null)
 */
class QrCodeFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return QrCodeFactory::class;
    }
}
