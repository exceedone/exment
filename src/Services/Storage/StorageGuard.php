<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomColumn;

/**
 * Guards of metadata changes that the conversion phases cannot absorb yet (02 §15.7 "Guard").
 *
 * Column definition changes (create, update, delete) are refused while the table is not plain
 * JSON: the staging columns are planned from the column list, and the physical add/drop/index
 * DDL of a converted table (§6) is not implemented. For a JSON table - every table in
 * production - nothing changes. State is read fresh (one query per column save/delete).
 */
final class StorageGuard
{
    public static function assertColumnChangeAllowed(CustomColumn $column, string $operation): void
    {
        if (!isset($column->custom_table_id)) {
            return;
        }
        try {
            $storage = TableStorage::fresh((int)$column->custom_table_id);
        } catch (\InvalidArgumentException $ex) {
            return; // no custom_tables row: nothing converted, behaviour as before
        }
        if ($storage->isJson()) {
            return;
        }
        throw new StorageConversionException("column $operation refused: table storage is {$storage->mode()}/{$storage->state()}", [
            'column' => $column->column_name,
            'storage' => $storage->toArray(),
        ]);
    }
}
