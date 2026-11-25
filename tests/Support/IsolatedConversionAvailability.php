<?php

namespace Exceedone\Exment\Tests\Support;

use Exceedone\Exment\Services\Storage\ConversionAvailability;

/**
 * TEST SUPPORT ONLY: "conversion available", bound by IsolatedConversionExecutionProvider when its
 * guards pass, or directly by integration tests on the isolated database.
 */
class IsolatedConversionAvailability implements ConversionAvailability
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function missing(): array
    {
        return [];
    }
}
