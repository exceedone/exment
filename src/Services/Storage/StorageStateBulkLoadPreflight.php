<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\BulkLoadRejectedException;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\BulkLoad\BulkLoadContext;
use Exceedone\Exment\Services\BulkLoad\BulkLoadPreflight;

/**
 * Production BulkLoadPreflight from Stage B block 1 (replaces AllowAllBulkLoadPreflight).
 *
 * A bulk load writes the JSON "value" only and bypasses every event (02 §11.5, §15.4, W4):
 *  - exment:bulkinsert is refused for a table whose storage is not plain JSON;
 *  - a restore (DROP/TRUNCATE + load of the whole database, metadata included) is refused while
 *    any table of this installation is not plain JSON (§15.7 guard).
 * With every table in JSON - the only state reachable in production - it allows everything,
 * exactly as before. State is read fresh; before the storage migration every table is JSON.
 */
class StorageStateBulkLoadPreflight implements BulkLoadPreflight
{
    public function check(BulkLoadContext $context): void
    {
        if ($context->operation() === BulkLoadContext::OPERATION_BULK_INSERT) {
            $table = CustomTable::getEloquent((string)($context->target()['table_name'] ?? ''));
            if (!$table) {
                return; // the command reports an unknown table itself, as before
            }
            $storage = TableStorage::fresh($table);
            if (!$storage->isJson()) {
                throw new BulkLoadRejectedException("bulkinsert refused: table {$table->table_name} storage is {$storage->mode()}/{$storage->state()}, bulk loads write JSON only");
            }
            return;
        }

        if ($context->operation() === BulkLoadContext::OPERATION_RESTORE) {
            $ids = TableStorage::nonJsonTableIds();
            if (!empty($ids)) {
                throw new BulkLoadRejectedException('restore refused: tables in storage conversion (custom_tables.id ' . implode(', ', $ids) . '); return them to JSON first');
            }
        }
    }
}
