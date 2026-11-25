<?php

namespace Exceedone\Exment\Services\Storage;

/**
 * Whether table conversion may be started at all in this installation.
 *
 * Production binding: UnavailableConversion (never available) until dual-write, backfill,
 * verification and cutover protections exist. Tests of the schema manager bind their own.
 */
interface ConversionAvailability
{
    public function isAvailable(): bool;

    /**
     * What is missing before conversion can be offered (codes), empty when available.
     *
     * @return array<int, string>
     */
    public function missing(): array;
}
