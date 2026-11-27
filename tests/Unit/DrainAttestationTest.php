<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Storage\DrainAttestation;
use Exceedone\Exment\Exceptions\StorageConversionException;
use PHPUnit\Framework\TestCase;

class DrainAttestationTest extends TestCase
{
    private function make(string $by, array $codes, string $measures): DrainAttestation
    {
        return new DrainAttestation($by, new \DateTimeImmutable(), array_fill_keys($codes, true), $measures);
    }

    public function testMeasuresAreOptional(): void
    {
        $this->make('admin', DrainAttestation::REQUIRED, '')->assertComplete();
        $this->assertTrue(true);
    }

    public function testAttestorAndEveryConfirmationAreStillRequired(): void
    {
        $codes = DrainAttestation::REQUIRED;
        $dropped = array_shift($codes);
        try {
            $this->make('admin', $codes, 'done')->assertComplete();
            $this->fail('a missing confirmation must be rejected');
        } catch (StorageConversionException $ex) {
            $this->assertContains($dropped, $ex->details()['missing'] ?? []);
        }

        $this->expectException(StorageConversionException::class);
        $this->make('', DrainAttestation::REQUIRED, 'done')->assertComplete();
    }
}
