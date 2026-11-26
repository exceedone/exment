<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;

/**
 * What the views ask of the columns of the large tables (ISS02927): the filters (of the view and
 * of the list), the sorts and the groups of the summary views, read with one query each. A column
 * without "search index" that a view filters, sorts or groups by, or a reference column (it joins),
 * reads every row of a large table.
 */
class ViewUsage
{
    public const FILTER = 'filter';
    public const SORT = 'sort';
    public const GROUP = 'group';
    public const REFERENCE = 'reference';
    public const CHILD_SUMMARY = 'child_summary';

    /** The types that a search index does not help (long texts). */
    public const NOT_INDEXED_TYPES = [ColumnType::TEXTAREA, ColumnType::EDITOR];

    /** @var array<int, int>|null the large tables: id => rows */
    protected $large;

    /** @var array<int, array{view_id: int, table_id: int, kind: string, use: string, column_id: int|null, column_table_id: int}>|null */
    protected $uses;

    /**
     * @param array<string, mixed>|null $stats what DataStats counted
     */
    public function __construct(?array $stats)
    {
        $this->large = [];
        foreach ((array) ($stats['tables'] ?? []) as $name => $counts) {
            if (intval($counts['rows'] ?? 0) < SearchIndexTuning::LARGE_ROWS) {
                continue;
            }
            $table = CustomTable::getEloquent($name);
            if (isset($table)) {
                $this->large[intval($table->id)] = intval($counts['rows']);
            }
        }
    }

    /**
     * @return array<int, int> the ids of the large tables => their rows
     */
    public function largeTables(): array
    {
        return $this->large ?? [];
    }

    /**
     * The conditions of the views of the large tables.
     *
     * @return array<int, array{view_id: int, table_id: int, kind: string, use: string, column_id: int|null, column_table_id: int}>
     */
    protected function uses(): array
    {
        if (isset($this->uses)) {
            return $this->uses;
        }
        $this->uses = [];
        if (count($this->largeTables()) == 0) {
            return $this->uses;
        }
        $sources = [
            ['custom_view_filters', static::FILTER],
            ['custom_view_grid_filters', static::FILTER],
            ['custom_view_sorts', static::SORT],
            // the columns of a summary view are its groups
            ['custom_view_columns', static::GROUP],
            ['custom_view_summaries', static::CHILD_SUMMARY],
        ];
        foreach ($sources as [$dbTable, $use]) {
            if (!hasTable($dbTable)) {
                continue;
            }
            $query = \DB::table($dbTable)
                ->join('custom_views', 'custom_views.id', '=', "{$dbTable}.custom_view_id")
                ->whereIn('custom_views.custom_table_id', array_keys($this->largeTables()))
                ->select(['custom_views.id as view_id', 'custom_views.custom_table_id as table_id', 'custom_views.view_kind_type as kind', "{$dbTable}.view_column_type as type", "{$dbTable}.view_column_target_id as target_id", "{$dbTable}.view_column_table_id as column_table_id"]);
            if ($use === static::GROUP || $use === static::CHILD_SUMMARY) {
                $query->where('custom_views.view_kind_type', ViewKindType::AGGREGATE);
            }
            foreach ($query->get() as $row) {
                $isColumn = strval($row->type) === ConditionType::COLUMN;
                $this->uses[] = [
                    'view_id' => intval($row->view_id),
                    'table_id' => intval($row->table_id),
                    'kind' => strval($row->kind),
                    'use' => $use,
                    'column_id' => $isColumn && is_numeric($row->target_id) ? intval($row->target_id) : null,
                    'column_table_id' => intval($row->column_table_id),
                ];
            }
        }
        return $this->uses;
    }

    /**
     * The columns without a search index of the large tables that the views use, and the
     * reference columns: column id => the uses (filter, sort, group, reference).
     *
     * @return array<int, array{column: CustomColumn, table: CustomTable, rows: int, uses: array<int, string>}>
     */
    public function unindexedColumns(): array
    {
        $result = [];
        $add = function (CustomColumn $column, string $use) use (&$result) {
            if (boolval($column->index_enabled) || in_array($column->column_type, static::NOT_INDEXED_TYPES, true)) {
                return;
            }
            $tableId = intval($column->custom_table_id);
            if (!isset($this->large[$tableId])) {
                return;
            }
            $id = intval($column->id);
            if (!isset($result[$id])) {
                $table = CustomTable::getEloquent($tableId);
                if (!isset($table)) {
                    return;
                }
                $result[$id] = ['column' => $column, 'table' => $table, 'rows' => $this->large[$tableId], 'uses' => []];
            }
            if (!in_array($use, $result[$id]['uses'], true)) {
                $result[$id]['uses'][] = $use;
            }
        };
        foreach ($this->uses() as $use) {
            // a summary column is summed, not looked up: the index does not help it
            if (!isset($use['column_id']) || $use['use'] === static::CHILD_SUMMARY) {
                continue;
            }
            $column = CustomColumn::getEloquent($use['column_id']);
            if ($column instanceof CustomColumn) {
                $add($column, $use['use']);
            }
        }
        foreach (array_keys($this->largeTables()) as $tableId) {
            $table = CustomTable::getEloquent($tableId);
            if (!isset($table)) {
                continue;
            }
            foreach ($table->custom_columns_cache as $column) {
                if (ColumnType::isSelectTable($column->column_type)) {
                    $add($column, static::REFERENCE);
                }
            }
        }
        // the most rows first, then the most uses
        uasort($result, function ($a, $b) {
            return [$b['rows'], count($b['uses'])] <=> [$a['rows'], count($a['uses'])];
        });
        return $result;
    }

    /**
     * The views of the large tables that read every row: a filter or a sort by a column without
     * a search index, a group of a summary view without one, a summary of a child table.
     *
     * @return array<int, array{view_id: int, table_id: int, rows: int, reasons: array<int, string>}>
     */
    public function heavyViews(): array
    {
        $unindexed = $this->unindexedColumns();
        $result = [];
        foreach ($this->uses() as $use) {
            $reason = null;
            if ($use['use'] === static::CHILD_SUMMARY) {
                if ($use['column_table_id'] > 0 && $use['column_table_id'] !== $use['table_id']) {
                    $reason = static::CHILD_SUMMARY;
                }
            } elseif (isset($use['column_id'], $unindexed[$use['column_id']])) {
                $reason = $use['use'];
            }
            if (is_null($reason)) {
                continue;
            }
            $id = $use['view_id'];
            if (!isset($result[$id])) {
                $result[$id] = ['view_id' => $id, 'table_id' => $use['table_id'], 'rows' => $this->large[$use['table_id']] ?? 0, 'reasons' => []];
            }
            if (!in_array($reason, $result[$id]['reasons'], true)) {
                $result[$id]['reasons'][] = $reason;
            }
        }
        uasort($result, function ($a, $b) {
            return [$b['rows'], count($b['reasons'])] <=> [$a['rows'], count($a['reasons'])];
        });
        return $result;
    }
}
