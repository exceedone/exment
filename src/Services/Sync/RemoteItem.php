<?php

namespace Exceedone\Exment\Services\Sync;

use Carbon\Carbon;

/**
 * One item of the service (ISS00113): its id there, its values (field code => value), when it was
 * made and changed there, its page there.
 */
class RemoteItem
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public string $id,
        public array $values,
        public ?Carbon $updatedAt = null,
        public ?Carbon $createdAt = null,
        public ?string $url = null,
        public bool $hasAttachments = false
    ) {
    }

    /**
     * What tells a change: the values (the same values, no new write).
     */
    public function hash(): string
    {
        $values = $this->values;
        ksort($values);
        return hash('sha256', strval(json_encode($values, JSON_UNESCAPED_UNICODE)));
    }
}
