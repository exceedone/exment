<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;

/**
 * Storage-aware query keys (Stage B block 4, 02 §15.15), applied through the P1 contract
 * CustomColumn::getQueryKeyFor($purpose).
 *
 * On a table that reads physical (TableStorage::readsPhysical, from the cached model - safe for
 * reads, see TableStorage::fromModel), a single-valued column whose physical type is NOT a
 * string type resolves to its typed column `pcol_<suuid>` for the purposes whose semantics on
 * typed values are decided (L5, 02 §15.5):
 *   QUERY_PURPOSE_GENERAL (select / where / group / summary / join), SORT, CAST.
 * Everything else keeps the existing key (index column or `value->col`), read from the JSON
 * shadow, which is complete and synchronized in physical_read - an explicit choice, not a guess:
 *   - multi-valued columns (always JSON, R2);
 *   - string types (text/textarea/editor/url, email/select/valtext/auto_number/boolean,
 *     file/image): collation of typed strings is open (D-6);
 *   - NULL_CHECK: empty semantics of a cell held only in value_original is open (D-3);
 *   - LOOKUP: coercion of a typed input to a typed column is not designed (§15.11.2);
 *   - UNIQUE: duplicate normalization on typed values is open (§15.11.11);
 *   - CONTAINS: element matching of a scalar typed value is not designed (§15.11.6).
 * The key is relative to the column's OWN table (custom_table_cache), so callers qualify it with
 * that table's name or join alias exactly as they qualify the existing key.
 * Index-name policies (ColumnQueryKey index / index_without_ddl / index_if_present) and direct
 * getQueryKey() / getQueryKeyWithoutAlter() callers keep the generated index column, which is
 * computed from the JSON shadow.
 */
final class PhysicalQueryRouting
{
    private const ROUTED_PURPOSES = [
        CustomColumn::QUERY_PURPOSE_GENERAL,
        CustomColumn::QUERY_PURPOSE_SORT,
        CustomColumn::QUERY_PURPOSE_CAST,
    ];

    private const STRING_TYPES = [
        PhysicalColumnType::MEDIUMTEXT,
        PhysicalColumnType::VARCHAR255,
        PhysicalColumnType::VARCHAR768,
    ];

    /**
     * The typed column of $column for $purpose, or null to keep the existing key.
     */
    public static function keyFor(CustomColumn $column, string $purpose): ?string
    {
        if (!in_array($purpose, self::ROUTED_PURPOSES, true)) {
            return null;
        }
        $table = $column->custom_table_cache;
        if (!($table instanceof CustomTable) || !TableStorage::fromModel($table)->readsPhysical()) {
            return null;
        }
        $type = PhysicalColumnType::of($column);
        if ($type === null || !static::isRoutedType($type)) {
            return null;
        }
        return PhysicalNaming::stagingColumn($column);
    }

    /**
     * Whether `CAST(<column> AS <cast type>)` returns the typed column unchanged, so the filter /
     * sort expression can use the column itself (and its index). True only when ALL hold:
     *  - the key being cast IS the column's typed column on a table reading physical (resolved from
     *    storage state and column metadata; a name that merely looks like pcol_* does not count);
     *  - the cast type is the logical type the physical type stores:
     *      integer (signed)  <- bigint / int unsigned   (value-preserving, ordering identical)
     *      date, datetime, time <- date / datetime / time (datetime(0): no fractional part to cut)
     *      decimal(p, s)     <- decimal(22,8) only when s >= 8 and p >= 22 (a smaller scale ROUNDS:
     *                           the cast is then a real transformation and stays).
     * Strings never get a cast. NULL stays NULL in both forms.
     *
     * @param array<string, mixed> $castOptions options of CustomItem::getCastOptions()
     */
    public static function isCastRedundant(CustomColumn $column, string $resolvedKey, string $castType, array $castOptions): bool
    {
        $typed = static::keyFor($column, CustomColumn::QUERY_PURPOSE_CAST);
        if ($typed === null || $resolvedKey !== $typed) {
            return false;
        }
        $physical = PhysicalColumnType::of($column);
        switch ($castType) {
            case \Exceedone\Exment\Enums\DatabaseDataType::TYPE_INTEGER:
                // SQL Server: the grammar's integer cast is `int` (32 bit) and CAST(bigint AS int) RAISES an
                // overflow error (8115) above 2147483647 [measured]. On a physical BIGINT column the cast is
                // therefore NOT an identity - it is a defect of the JSON-era expression (the JSON path keeps
                // it: unchanged). The integer semantics the operation wants (compare / order as an integer)
                // are the column's own, so the cast is dropped: values beyond the signed INT range filter
                // and sort correctly instead of failing the whole query. INTENTIONAL OVERFLOW BUG FIX
                // (physical_read tables only; documented 02 §15.21).
                return in_array($physical, [PhysicalColumnType::BIGINT, PhysicalColumnType::UINT], true);
            case \Exceedone\Exment\Enums\DatabaseDataType::TYPE_DATE:
                return $physical === PhysicalColumnType::DATE;
            case \Exceedone\Exment\Enums\DatabaseDataType::TYPE_DATETIME:
                return $physical === PhysicalColumnType::DATETIME;
            case \Exceedone\Exment\Enums\DatabaseDataType::TYPE_TIME:
                return $physical === PhysicalColumnType::TIME;
            case \Exceedone\Exment\Enums\DatabaseDataType::TYPE_DECIMAL:
                if ($physical !== PhysicalColumnType::DECIMAL) {
                    return false;
                }
                // CustomItem option-less decimal casts with the grammar default (length 50, digits 2)
                $length = (int)($castOptions['length'] ?? 50);
                $digits = (int)($castOptions['decimal_digit'] ?? 2);
                return $length >= 22 && $digits >= 8;
        }
        return false;
    }

    /** Whether queries on a table reading physical use the typed column of this physical type. */
    public static function isRoutedType(string $type): bool
    {
        return !in_array($type, self::STRING_TYPES, true);
    }

    /**
     * Whether a read may create a missing generated index column (getIndexColumnName auto-DDL).
     * Only plain JSON tables: during conversion and on physical tables a read never runs DDL - a
     * missing column makes the query fail instead.
     */
    public static function allowsReadTimeDdl(?CustomTable $table): bool
    {
        return !($table instanceof CustomTable) || TableStorage::fromModel($table)->isJson();
    }
}
