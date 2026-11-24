<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Facades\QrCodeFacade;
use Exceedone\Exment\Services\Line\QrRenderer;

/**
 * The QR code is made with bacon/bacon-qr-code 3 (#1902). The calls of simple-qrcode
 * (format, size, margin, generate) still work. The "QrCode" alias: QrCodeAliasTest.
 */
class QrCodeBuilderTest extends UnitTestBase
{
    public function testSvg(): void
    {
        $svg = QrCodeFacade::format('svg')->size(256)->margin(1)->generate('https://example.com/');
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('width="256"', $svg);
    }

    public function testPng(): void
    {
        $png = QrCodeFacade::format('png')->size(200)->generate('otpauth://totp/exment?secret=ABC');
        $this->assertIsString($png);
        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);
        $this->assertSame(200, imagesx($image));
        $this->assertSame(200, imagesy($image));
    }

    public function testPngToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qr');
        try {
            $result = QrCodeFacade::format('png')->size(120)->margin(0)->generate('https://example.com/', $path);
            $this->assertNull($result);
            $image = imagecreatefrompng($path);
            $this->assertNotFalse($image);
            $this->assertSame(120, imagesx($image));
        } finally {
            @unlink($path);
        }
    }

    public function testEachCallStartsFresh(): void
    {
        QrCodeFacade::format('png')->size(50)->generate('a');
        // the next QR code does not keep "png" from the previous one
        $this->assertStringContainsString('<svg', QrCodeFacade::generate('b'));
    }

    public function testTheOldClassNameStillWorks(): void
    {
        // code written for simplesoftwareio/simple-qrcode
        $png = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')->size(80)->generate('old');
        $image = imagecreatefromstring(strval($png));
        $this->assertNotFalse($image);
        $this->assertSame(80, imagesx($image));
    }

    public function testLineRenderer(): void
    {
        $this->assertStringStartsWith('data:image/svg+xml;base64,', QrRenderer::svgDataUri('https://line.me/'));
    }

    public function testUnknownFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCodeFacade::format('eps');
    }
}
