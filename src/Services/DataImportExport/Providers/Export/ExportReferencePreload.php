<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;

/**
 * Loads the records an export window refers to (select_table columns with "select_export_column_id", and the parent id of a
 * child table with "parent_export_column_id") into the request cache in a few batches, before the window is converted.
 *
 * Why: DefaultTableProvider::getBodyValue() resolves every reference with getValueModel($id), which is a `where id = ?` query
 * per distinct id (the first one only: it is cached afterwards).
 *
 * Nothing is re-implemented here. CustomTable::setCustomValueModels() is the existing request-cache loader: same scoped query
 * (CustomValueModelScope / permissions, soft deletes) as the per-id find(), it skips ids that are already cached, and the
 * models it caches are the ones getValueModel($id) would have cached. The conditions under which the export looks a reference
 * up are repeated exactly, so no id is loaded that the export would not have loaded. Ids that are not canonical integers, and
 * ids that are missing or not visible to the user, are not cached and simply take the original per-id path.
 */
class ExportReferencePreload
{
    /** ids per query */
    public const BATCH_SIZE = 500;

    /**
     * @param CustomTable $customTable the exported table
     * @param iterable<mixed> $records one window of the exported records
     * @param array<mixed> $columnDefines [first columns, custom columns, last columns] as returned by getColumnDefines()
     * @return int number of ids requested from the database
     */
    public static function preload(CustomTable $customTable, iterable $records, array $columnDefines): int
    {
        // target table id => ['table' => CustomTable, 'ids' => [id => true]]
        $targets = [];

        // resolved once per window, not per record
        $selectColumns = [];
        foreach (static::getSelectColumns($columnDefines) as $column) {
            $selectColumns[] = [$column->column_name, $column->select_target_table];
        }
        $hasParentId = in_array('parent_id', $columnDefines[0] ?? [], true);
        if (empty($selectColumns) && !$hasParentId) {
            return 0;
        }
        $parentTables = [];

        foreach ($records as $record) {
            if (!empty($selectColumns)) {
                $values = array_get($record, 'value');
                if (is_array($values)) {
                    foreach ($selectColumns as [$columnName, $table]) {
                        foreach (static::flatten($values[$columnName] ?? null) as $id) {
                            $targets[$table->id]['table'] = $table;
                            $targets[$table->id]['ids'][$id] = true;
                        }
                    }
                }
            }

            if ($hasParentId) {
                $parentId = array_get($record, 'parent_id');
                $parentType = array_get($record, 'parent_type');
                if (is_nullorempty($parentId) || is_nullorempty($parentType) || !is_scalar($parentType)) {
                    continue;
                }
                $parentKey = (string)$parentType;
                if (!array_key_exists($parentKey, $parentTables)) {
                    $parentTables[$parentKey] = static::getParentTable($customTable, $parentType);
                }
                $table = $parentTables[$parentKey];
                if (!isset($table)) {
                    continue;
                }
                foreach (static::flatten($parentId, false) as $id) {
                    $targets[$table->id]['table'] = $table;
                    $targets[$table->id]['ids'][$id] = true;
                }
            }
        }

        $requested = 0;
        foreach ($targets as $target) {
            foreach (array_chunk(array_map('strval', array_keys($target['ids'])), static::BATCH_SIZE) as $slice) {
                $target['table']->setCustomValueModels($slice);
                $requested += count($slice);
            }
        }

        return $requested;
    }

    /**
     * The select_table columns whose value the export converts (same conditions as getSelectTableExportValue()).
     *
     * @param array<mixed> $columnDefines
     * @return array<int, \Exceedone\Exment\Model\CustomColumn>
     */
    protected static function getSelectColumns(array $columnDefines): array
    {
        $result = [];
        foreach ($columnDefines[1] ?? [] as $column) {
            if (!ColumnType::isSelectTable(array_get($column, 'column_type'))) {
                continue;
            }
            if (is_nullorempty(array_get($column, 'options.select_export_column_id'))) {
                continue;
            }
            if (is_nullorempty($column->select_target_table)) {
                continue;
            }
            $result[] = $column;
        }

        return $result;
    }

    /**
     * The parent table getParentExportValue() would read from, or null when it returns the id untouched.
     *
     * @param CustomTable $customTable
     * @param mixed $parentType
     * @return CustomTable|null
     */
    protected static function getParentTable(CustomTable $customTable, $parentType): ?CustomTable
    {
        $relation = CustomRelation::getRelationByParentChild($parentType, $customTable);
        if (!isset($relation)) {
            return null;
        }
        if (is_nullorempty(array_get($relation, 'options.parent_export_column_id'))) {
            return null;
        }
        $parentTable = CustomTable::getEloquent($parentType);

        return is_nullorempty($parentTable) ? null : $parentTable;
    }

    /**
     * Canonical positive integer ids of a column value (a scalar, or a list when the column accepts several values).
     * Anything else keeps the original per-id path.
     *
     * @param mixed $value
     * @param bool $allowList
     * @return array<int, string>
     */
    protected static function flatten($value, bool $allowList = true): array
    {
        if (is_array($value)) {
            if (!$allowList) {
                return [];
            }
            $values = $value;
        } else {
            $values = [$value];
        }

        $ids = [];
        foreach ($values as $v) {
            if (is_int($v)) {
                $v = (string)$v;
            }
            if (is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/', $v)) {
                $ids[] = $v;
            }
        }

        return $ids;
    }
}
