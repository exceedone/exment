<?php

namespace Exceedone\Exment\Services\BulkLoad;

/**
 * Asked once before a bulk load writes its target: exment:bulkinsert (per file, before LOAD DATA)
 * and restore (Restore::execute, after the backup is unzipped and before any table is dropped,
 * truncated or replaced).
 *
 * To refuse, throw BulkLoadRejectedException; return to allow. Production implementation since
 * Stage B block 1: Services\Storage\StorageStateBulkLoadPreflight (refuses only while a table is
 * not plain JSON storage). AllowAllBulkLoadPreflight remains as the allow-everything variant.
 */
interface BulkLoadPreflight
{
    /**
     * @throws \Exceedone\Exment\Exceptions\BulkLoadRejectedException
     */
    public function check(BulkLoadContext $context): void;
}
