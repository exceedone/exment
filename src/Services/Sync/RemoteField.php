<?php

namespace Exceedone\Exment\Services\Sync;

/**
 * A field of a unit of the service (ISS00113). $type is the column type of Exment it fits
 * (text, textarea, integer, decimal, date, datetime, select, user, url, email, file, ...), or
 * null when it has none (the dry run lists it as "to fix by hand").
 */
class RemoteField
{
    /**
     * @param array<int, string> $options the choices of a select
     */
    public function __construct(
        public string $code,
        public string $label,
        public ?string $type,
        public bool $required = false,
        public array $options = [],
        public bool $multiple = false
    ) {
    }
}
