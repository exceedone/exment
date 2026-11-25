<?php

namespace Exceedone\Exment\Services\BulkLoad;

/**
 * The production BulkLoadPreflight: allows every bulk load, as before (JSON storage only).
 */
class AllowAllBulkLoadPreflight implements BulkLoadPreflight
{
    public function check(BulkLoadContext $context): void
    {
    }
}
