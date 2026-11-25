<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;

/**
 * Write protocol of value writes against their table's storage state (Stage B block 3).
 *
 * Participation: once the storage columns are installed, EVERY value write Exment performs -
 * model save (CustomValue::save) and set-based write (StorageBulkWrite) - runs in a transaction
 * that first reads its table's storage row under a SHARED lock (TableStorage::lockedForWrite)
 * and decides from the locked values; the lock is held until commit. JSON-only writes included:
 * a transition (exclusive lock on the same row) therefore waits for every participating write
 * that started under the previous state, and every write that starts after it sees the new state.
 * This closes the provisioned -> dualwrite race for participating writes; it does not reach
 * processes running older code, raw SQL, or plugins writing `value->` themselves - those need the
 * operational drain/exclusion attested at StorageTransitions::startBackfill().
 *
 * Lock order (all participants): 1. custom_tables row of the target table (S for writes and
 * runs, X for transitions), 2. value rows. A transition holds only its one custom_tables row.
 *
 * Generation fencing: the generation is read under the lock and cannot change before commit;
 * runs store the generation they started at and stop (stale) when it differs.
 */
final class StorageWriteGate
{
    public const JSON_ONLY = 'json_only';
    public const DUAL_WRITE = 'dual_write';

    /**
     * The locked storage state of a table for a write inside the current transaction.
     *
     * @return TableStorage|null null when storage is not installed or the table row is gone
     */
    public static function lock(CustomTable $table): ?TableStorage
    {
        return TableStorage::lockedForWrite((int)$table->id);
    }

    /**
     * @return string self::JSON_ONLY | self::DUAL_WRITE
     * @throws StorageConversionException write refused (physical_read, cleaning, cleaned, failed)
     */
    public static function classify(?TableStorage $storage, CustomTable $table): string
    {
        if ($storage === null) {
            return self::JSON_ONLY;
        }
        $state = $storage->state();
        if (in_array($state, StorageState::jsonOnlyWriteStates(), true) && $storage->mode() === StorageMode::JSON) {
            return self::JSON_ONLY;
        }
        if (in_array($state, StorageState::dualWriteStates(), true) && $storage->mode() === StorageMode::JSON) {
            return self::DUAL_WRITE;
        }
        // physical reads: writes keep the typed columns, value_original AND the complete JSON shadow
        if ($state === StorageState::PHYSICAL_READ && $storage->mode() === StorageMode::PHYSICAL) {
            return self::DUAL_WRITE;
        }
        throw new StorageConversionException("write refused: table {$table->table_name} storage is {$storage->mode()}/{$state}", ['storage' => $storage->toArray()]);
    }
}
