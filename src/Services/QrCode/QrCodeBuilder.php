<?php

namespace Exceedone\Exment\Services\QrCode;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Makes a QR code with bacon/bacon-qr-code.
 *
 * It keeps the calls of simplesoftwareio/simple-qrcode that Exment and plugins use:
 *   QrCode::format('png')->size(200)->margin(0)->generate($text[, $filename])
 * so a plugin that calls the "QrCode" alias still works (#1902).
 */
class QrCodeBuilder
{
    public const FORMAT_SVG = 'svg';
    public const FORMAT_PNG = 'png';

    protected string $format = self::FORMAT_SVG;
    protected int $size = 100;
    protected int $margin = 0;
    protected string $errorCorrection = 'L';
    protected string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING;

    /**
     * @param string $format svg or png
     * @return $this
     */
    public function format(string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, [static::FORMAT_SVG, static::FORMAT_PNG], true)) {
            throw new \InvalidArgumentException("The QR code format {$format} is not supported. Use svg or png.");
        }
        $this->format = $format;
        return $this;
    }

    /**
     * @param int $size the width and height in pixels, margin included
     * @return $this
     */
    public function size(int $size)
    {
        $this->size = max(1, $size);
        return $this;
    }

    /**
     * @param int $margin the quiet zone, in modules
     * @return $this
     */
    public function margin(int $margin)
    {
        $this->margin = max(0, $margin);
        return $this;
    }

    /**
     * @param string $level L, M, Q or H
     * @return $this
     */
    public function errorCorrection(string $level)
    {
        $level = strtoupper($level);
        if (!in_array($level, ['L', 'M', 'Q', 'H'], true)) {
            throw new \InvalidArgumentException("The error correction level {$level} is not supported. Use L, M, Q or H.");
        }
        $this->errorCorrection = $level;
        return $this;
    }

    /**
     * @return $this
     */
    public function encoding(string $encoding)
    {
        $this->encoding = $encoding;
        return $this;
    }

    /**
     * Make the QR code.
     *
     * @param string $text
     * @param string|null $filename when given, the image is written to this file and null is returned
     * @return string|null the svg markup or the png bytes
     */
    public function generate(string $text, ?string $filename = null): ?string
    {
        $writer = new Writer($this->renderer());
        $image = $writer->writeString($text, $this->encoding, ErrorCorrectionLevel::{$this->errorCorrection}());

        if ($filename !== null) {
            file_put_contents($filename, $image);
            return null;
        }
        return $image;
    }

    /**
     * @return \BaconQrCode\Renderer\RendererInterface
     */
    protected function renderer()
    {
        if ($this->format === static::FORMAT_PNG) {
            // GD is already needed by Exment (the QR sticker); imagick is not.
            return new GDLibRenderer($this->size, $this->margin);
        }
        return new ImageRenderer(new RendererStyle($this->size, $this->margin), new SvgImageBackEnd());
    }
}
