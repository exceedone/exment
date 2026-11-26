<?php

namespace Exceedone\Exment\Services\Sync;

use Exceedone\Exment\Model\SyncConnection;
use Exceedone\Exment\Model\SyncMapping;

/**
 * A connector with settings of its own on a mapping (ISS00113), shown on the page of the mapping
 * by the engine and kept in its options ("connector"): as the Exment project a Backlog project goes
 * to. Fields only, the page draws them: no HTML of a plugin on the page.
 */
interface SyncMappingFields
{
    /**
     * The fields: name => ['label' => string, 'type' => 'text'|'select', 'options' => [value => label], 'help' => string].
     *
     * @return array<string, array<string, mixed>>
     */
    public function mappingFields(SyncConnection $connection, string $object, SyncMapping $mapping): array;

    /**
     * Check the values posted (name => value): null when they are right, else what is wrong.
     *
     * @param array<string, string> $values
     */
    public function checkMappingFields(SyncConnection $connection, string $object, array $values): ?string;
}
