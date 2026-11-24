<?php

namespace Exceedone\Exment\Services\QrCode;

/**
 * The object behind the QrCode facade. Each call starts a new builder, so the settings of one
 * QR code (format, size ...) never stay for the next one.
 *
 * @method QrCodeBuilder format(string $format)
 * @method QrCodeBuilder size(int $size)
 * @method QrCodeBuilder margin(int $margin)
 * @method QrCodeBuilder errorCorrection(string $level)
 * @method QrCodeBuilder encoding(string $encoding)
 * @method string|null generate(string $text, ?string $filename = null)
 */
class QrCodeFactory
{
    /**
     * @param string $method
     * @param array<mixed> $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return (new QrCodeBuilder())->{$method}(...$arguments);
    }
}
