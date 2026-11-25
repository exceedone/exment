<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Model\CustomTable;

/**
 * Loads the records a sheet / chunk points at through its select_table (user, organization) columns into the request
 * cache in a few batches, before the rows are validated.
 *
 * Why: the import converts "code" values to ids after DefaultTableProvider calls setSelectTableValues() (which therefore only
 * saw the raw file values), so CustomValueRule::hasData() ran one `where id = ?` query per distinct referenced id.
 *
 * Nothing is re-implemented here. CustomTable::setCustomValueModels() is the existing request-cache loader: same scoped query
 * (CustomValueModelScope / permissions, soft deletes) as the per-id find(), it skips ids that are already cached, and the
 * models it caches are the ones getValueModel($id) would have cached. What is not loaded here (non canonical ids, ids above
 * MAX_PRELOADED, ids that are missing or not visible to the user) simply takes the original per-id path.
 */
class ImportSelectTablePreload
{
    /** ids per query */
    public const BATCH_SIZE = 500;

    /** distinct ids per target table preloaded for one sheet / chunk; the rest takes the original per-id path */
    public const MAX_PRELOADED = 10000;

    /**
     * @param CustomTable $customTable the imported table
     * @param iterable<int|string, array<string, mixed>> $dataObjects converted rows ('data' => [...]) of the sheet / chunk
     * @return int number of ids requested from the database
     */
    public static function preload(CustomTable $customTable, iterable $dataObjects): int
    {
        // target table id => [table, id => true]
        $targets = [];
        foreach ($customTable->getSelectTableColumns() as $column) {
            $targetTable = $column->select_target_table;
            if (!isset($targetTable)) {
                continue;
            }
            $targets[$targetTable->id]['table'] = $targetTable;
            $targets[$targetTable->id]['columns'][] = $column->column_name;
        }

        $requested = 0;
        foreach ($targets as $target) {
            $ids = static::collectIds($target['columns'], $dataObjects);
            foreach (array_chunk($ids, static::BATCH_SIZE) as $slice) {
                $target['table']->setCustomValueModels($slice);
                $requested += count($slice);
            }
        }

        return $requested;
    }

    /**
     * Distinct canonical ids of the given columns, in first-appearance order, at most MAX_PRELOADED.
     *
     * @param array<int, string> $columnNames
     * @param iterable<int|string, array<string, mixed>> $dataObjects
     * @return array<int, string>
     */
    protected static function collectIds(array $columnNames, iterable $dataObjects): array
    {
        $ids = [];
        foreach ($dataObjects as $row) {
            $data = $row['data'] ?? null;
            if (!is_array($data)) {
                continue;
            }
            foreach ($columnNames as $columnName) {
                $raw = array_get($data, "value.$columnName");
                // same reading as the rule: a list, or a comma separated string
                foreach (is_int($raw) ? [$raw] : (is_array($raw) || is_string($raw) ? stringToArray($raw) : []) as $id) {
                    if (is_int($id)) {
                        $id = (string)$id;
                    }
                    // anything but a plain positive integer keeps the original per-id path
                    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,17}$/', $id)) {
                        continue;
                    }
                    $ids[$id] = true;
                    if (count($ids) >= static::MAX_PRELOADED) {
                        break 3;
                    }
                }
            }
        }

        return array_map('strval', array_keys($ids));
    }
}
