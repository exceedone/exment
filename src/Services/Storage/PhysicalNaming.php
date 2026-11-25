<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Model\CustomColumn;

/**
 * Names of the physical storage objects of a table and its columns (02 §3.1, §13.4 N2-O, §15.13).
 *
 * Typed column: `pcol_<column_suuid>`, from provisioning on AND after cutover (decision O-5,
 * 2026-11-25: the name is kept, no rename). Never `column_<column_suuid>`: that is the generated
 * (virtual) index column of an indexed column in JSON mode, and several P1 read paths use that
 * name unconditionally or "if the column exists" (ColumnQueryKey index / index_if_present,
 * ColumnItem authority, SyncMeiliReferencesJob, getChildrenValues).
 *
 * value_original: one JSON column per value table, `value_original`.
 *
 * Names are built from the suuid only (no metadata lookup, no DDL); the suuid is validated so
 * the name is a safe identifier on every driver. Every name is decided here only.
 */
final class PhysicalNaming
{
    public const STAGING_PREFIX = 'pcol_';
    public const STAGING_INDEX_PREFIX = 'index_pcol_';
    /** JSON object keyed by column_name: original values typed storage cannot reconstruct. */
    public const VALUE_ORIGINAL = 'value_original';
    /** Prefix of the generated index column in JSON mode (CustomColumn::getIndexColumnName). */
    public const VIRTUAL_PREFIX = 'column_';

    /** MySQL/MariaDB identifier limit (SQL Server allows 128). */
    public const MAX_IDENTIFIER_LENGTH = 64;

    /** Typed staging column of a column. */
    public static function stagingColumn(CustomColumn $column): string
    {
        return self::STAGING_PREFIX . self::suuid($column);
    }

    /** Index of the staging column (created only when indexes are built: a later block). */
    public static function stagingIndex(CustomColumn $column): string
    {
        return self::STAGING_INDEX_PREFIX . self::suuid($column);
    }

    /** Name of the generated index column the column has (or would have) in JSON mode. */
    public static function virtualColumn(CustomColumn $column): string
    {
        return self::VIRTUAL_PREFIX . self::suuid($column);
    }

    /** Whether an attribute/column name is a staging column name. */
    public static function isStagingName(string $name): bool
    {
        if (strncmp($name, self::STAGING_PREFIX, 5) !== 0) {
            return false;
        }
        return (bool)preg_match('/^' . self::STAGING_PREFIX . '[0-9a-z]{1,40}$/', $name);
    }

    /** Whether an attribute is internal hybrid storage (typed column or value_original). */
    public static function isInternalAttribute(string $name): bool
    {
        return $name === self::VALUE_ORIGINAL || static::isStagingName($name);
    }

    private static function suuid(CustomColumn $column): string
    {
        $suuid = (string)$column->suuid;
        if (!preg_match('/^[0-9a-z]{1,40}$/', $suuid)) {
            throw new \InvalidArgumentException('custom column suuid is not a safe identifier: ' . json_encode($suuid));
        }
        $longest = self::STAGING_INDEX_PREFIX . $suuid;
        if (strlen($longest) > self::MAX_IDENTIFIER_LENGTH) {
            throw new \InvalidArgumentException("identifier too long: $longest");
        }
        return $suuid;
    }
}
