<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomColumn;

/**
 * Guards of metadata changes that the conversion phases cannot absorb (02 §15.7 "Guard").
 *
 * Column definition changes (create, update, delete): a plain JSON table - every table in production -
 * is unchanged; a table that reads physical is handled by PhysicalColumnLifecycle (02 §16: DDL of the
 * typed column, refusal of changes that cannot be made safely); every other state (initializing, the
 * conversion phases: the staging columns are planned from the column list) refuses. State is read fresh
 * (one query per column save/delete).
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
        if ($storage->state() === StorageState::PHYSICAL_READ && $storage->mode() === StorageMode::PHYSICAL) {
            app(PhysicalColumnLifecycle::class)->beforeChange($column, $operation);
            return;
        }
        throw new StorageConversionException("column $operation refused: table storage is {$storage->mode()}/{$storage->state()}", [
            'column' => $column->column_name,
            'storage' => $storage->toArray(),
        ]);
    }

    /** After a column row was saved (work that must follow the metadata). Nothing happens unless a lifecycle step queued it. */
    public static function columnSaved(CustomColumn $column): void
    {
        if (PhysicalColumnLifecycle::hasPending($column)) {
            app(PhysicalColumnLifecycle::class)->afterChange($column);
        }
    }

    /** After a column row was deleted. */
    public static function columnDeleted(CustomColumn $column): void
    {
        if (PhysicalColumnLifecycle::hasPending($column)) {
            app(PhysicalColumnLifecycle::class)->afterDeleted($column);
        }
    }
}
