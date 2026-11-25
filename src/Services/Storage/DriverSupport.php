<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\StorageConversionException;

/**
 * Driver capabilities of the conversion orchestration. An operation whose behaviour is not
 * established for the driver fails explicitly - no fallback is ever used silently.
 *
 *  - mariadb : every capability (value is LONGTEXT: compare-and-set compares the stored text
 *              byte for byte with a binary collation-independent `=` on the same text).
 *  - mysql   : every capability; value is a JSON column, so compare-and-set compares
 *              `value = CAST(? AS JSON)` (semantic comparison of the text read) [not yet run on MySQL 8].
 *  - sqlsrv  : (implemented 2026-11-25, NOT verified on a SQL Server instance)
 *      dual-write transition, backfill, verification, automatic indexes: supported, with
 *        - compare-and-set as `value COLLATE Latin1_General_100_BIN2 = ?` (the default
 *          collations are case-insensitive: a plain `=` would match a changed value);
 *        - batches capped below the 2,100-parameter limit and the 5,000-lock escalation
 *          threshold (StorageRunner::maxBatch);
 *        - decimals as pdo_sqlsrv returns them (".5" for 0.5) normalized by PublicValueRenderer;
 *        - CREATE INDEX ... WITH (ONLINE = ON) only on editions that support it, otherwise only
 *          with exment.storage_auto_index_sqlsrv_allow_offline (StorageAutoIndexer).
 *      BULK_REMOVE_KEY: supported since the SQL Server grammar implements
 *        compileUpdateRemovingJsonKey (JSON_MODIFY(..., NULL), lax mode = remove the property;
 *        02 §15.21). Before, ExtendedBuilderTrait::updateRemovingJsonKey had no SQL Server grammar.
 *        Column deletion on a converted table is refused by StorageGuard (every driver).
 *      BulkInsert / restore: not available on SQL Server in Exment (unchanged; explicit refusal).
 */
final class DriverSupport
{
    public const DUAL_WRITE_TRANSITION = 'dual_write_transition';
    public const BACKFILL = 'backfill';
    public const VERIFY = 'verify';
    public const BULK_REMOVE_KEY = 'bulk_remove_key';
    /** CREATE INDEX on typed columns, reconciled from the schema. */
    public const AUTO_INDEX = 'auto_index';

    /** Capabilities NOT available per driver family. */
    private const UNSUPPORTED = [
        // every capability of this class is available on all three families today
    ];

    /** Binary collation for exact nvarchar comparison on SQL Server. */
    public const SQLSRV_BINARY_COLLATION = 'Latin1_General_100_BIN2';

    public static function supports(string $capability): bool
    {
        $family = PhysicalSchemaManager::driverFamily();
        if (!in_array($family, ['mariadb', 'mysql', 'sqlsrv'], true)) {
            return false;
        }
        return !in_array($capability, self::UNSUPPORTED[$family] ?? [], true);
    }

    /**
     * @throws StorageConversionException
     */
    public static function require(string $capability): void
    {
        if (!static::supports($capability)) {
            throw new StorageConversionException("driver " . PhysicalSchemaManager::driverFamily() . " does not support $capability for hybrid storage", ['capability' => $capability]);
        }
    }

    /**
     * Compare-and-set condition "value is exactly what was read" for a forward backfill.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string|null $raw the `value` text as read
     */
    public static function whereValueIs($query, ?string $raw): void
    {
        static::require(self::BACKFILL);
        if ($raw === null) {
            $query->whereNull('value');
            return;
        }
        $column = $query->getGrammar()->wrap('value');
        switch (PhysicalSchemaManager::driverFamily()) {
            case 'mysql':
                $query->whereRaw("$column = CAST(? AS JSON)", [$raw]);
                return;
            case 'sqlsrv':
                $query->whereRaw("$column COLLATE " . self::SQLSRV_BINARY_COLLATION . ' = ?', [$raw]);
                return;
        }
        $query->whereRaw("$column = ?", [$raw]);
    }
}
