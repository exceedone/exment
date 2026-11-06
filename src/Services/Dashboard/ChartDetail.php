<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;

/**
 * The 明細 of a chart drawn from an aggregate view (Excel's 詳細の表示 on a pivot cell): the
 * rows behind the number of one point, listed on the table's own data list.
 *
 * A point is named by the stored values of the view's group columns it stands for — all of
 * them for a point of a single-series chart (a row of the view), the X and the series column
 * for a cell of a multi-series one (ChartPivot folds the other columns' rows into it). They
 * are read back on the view's OWN summary query, run on the box's rows (the dashboard
 * filter, the chart filter) and grouped by the row id besides: the rows whose group values
 * equal the point's are the rows behind it, whatever the view groups by — a date by year or
 * month, a select_table, a column of a parent or of a child table — since every value is
 * compared with what the very same expression gave the chart.
 *
 * The rows listed are the ones the measure summarizes: of the view's table, or of the 1:n /
 * n:n child table a measure of a child column sums up (its sub query per parent), so that a
 * 件数 point lists as many rows as it counts. A row without a value of the measure adds
 * nothing to it and is left out — what a COUNT of the column leaves out.
 *
 * Only the rows the user may see are listed: the view's table is read through its value
 * query (the user's permission scope), the list through the data grid's.
 */
final class ChartDetail
{
    /** alias of the row id the view's summary query is grouped by besides */
    public const ID = 'chart_detail_id';

    /** @var CustomTable the view's table */
    private $table;
    /** @var CustomView */
    private $view;
    /** @var CustomViewSummary the measure */
    private $measure;
    /** @var \Closure(): \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> the box's rows: the view's table, the box's filters applied */
    private $rows;
    /** @var CustomTable the table the rows behind a point are listed of */
    private $grain;
    /** @var CustomRelation|null the 1:n / n:n relation to $grain, null when it is $table */
    private $relation;

    /**
     * @param \Closure(): \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $rows
     */
    private function __construct(CustomTable $table, CustomView $view, CustomViewSummary $measure, \Closure $rows, CustomTable $grain, ?CustomRelation $relation)
    {
        $this->table = $table;
        $this->view = $view;
        $this->measure = $measure;
        $this->rows = $rows;
        $this->grain = $grain;
        $this->relation = $relation;
    }

    /**
     * The 明細 of a chart of $view drawn by $measure, or null when its rows cannot be listed:
     * a measure of a table the rows do not come from one by one (a table referring to the
     * view's table), a group column of another child table than the measure's, a table
     * the user may not view.
     *
     * @param \Closure(): \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $rows  the box's rows, filters applied
     */
    public static function resolve(CustomTable $table, CustomView $view, CustomViewSummary $measure, \Closure $rows): ?self
    {
        $measure_table = CustomTable::getEloquent($measure->view_column_table_id);
        if ($measure_table === null) {
            return null;
        }
        $grain = $table;
        $relation = null;
        if ($measure_table->id != $table->id) {
            $relation = CustomRelation::getRelationByParentChild($table, $measure_table);
            if ($relation !== null) {
                $grain = $measure_table;
            } elseif (!static::isLookup($table, $measure_table)) {
                return null;
            }
        }
        foreach ($view->custom_view_columns as $column) {
            $column_table_id = $column->view_column_table_id;
            if ($column_table_id == $table->id || $column_table_id == $grain->id) {
                continue;
            }
            // another child table than the rows listed: its rows would multiply theirs
            if ($relation !== null && CustomRelation::getRelationByParentChild($table, $column_table_id) !== null) {
                return null;
            }
        }
        if (!$grain->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return null;
        }
        return new self($table, $view, $measure, $rows, $grain, $relation);
    }

    /**
     * Whether $other is a table a row of $table looks up one record of: its parent, or the
     * target of one of its select_table columns.
     */
    private static function isLookup(CustomTable $table, CustomTable $other): bool
    {
        return CustomRelation::getRelationByParentChild($other, $table) !== null
            || $table->getSelectTableColumns($other)->isNotEmpty();
    }

    /**
     * The table the rows behind a point are listed of.
     */
    public function table(): CustomTable
    {
        return $this->grain;
    }

    /**
     * The view's group columns, by id.
     *
     * @return array<int|string, CustomViewColumn>
     */
    public function groupColumns(): array
    {
        $out = [];
        foreach ($this->view->custom_view_columns as $column) {
            $out[$column->id] = $column;
        }
        return $out;
    }

    /**
     * The keys of a point, as the chart hands them back: {view column id: stored value}, the
     * value a scalar or null (a group without a value). null when they name no group:
     * no key at all, a column that is no group column of the view, a value of another type.
     *
     * @param mixed $keys
     * @return array<int|string, string|int|float|bool|null>|null
     */
    public function keys($keys): ?array
    {
        if (!is_array($keys) || empty($keys)) {
            return null;
        }
        $columns = $this->groupColumns();
        $out = [];
        foreach ($keys as $id => $value) {
            if (!isset($columns[$id]) || !($value === null || is_scalar($value))) {
                return null;
            }
            $out[$id] = $value;
        }
        return $out;
    }

    /**
     * The point's group values, human-readable: the column's label (the view's name for it)
     * and the value as the chart labels it — 未設定 for a group without a value.
     *
     * @param array<int|string, string|int|float|bool|null> $keys
     * @return array<int, array{label: string, value: string}>
     */
    public function captions(array $keys): array
    {
        $columns = $this->groupColumns();
        $out = [];
        foreach ($keys as $id => $value) {
            $column = $columns[$id];
            $item = static::copyItem($column);
            $item->options(['summary' => true, 'summary_index' => ViewKindType::DEFAULT . '_' . $column->id]);
            $text = '';
            if ($value !== null) {
                // the item reads a summary row: one carrying the value under the item's name
                $row = $this->table->getValueModel();
                $row->setRawAttributes([$item->uniqueName() => $value]);
                $text = $item->setCustomValue($row)->text();
                $text = is_array($text) ? implode(', ', $text) : (string) $text;
            }
            $out[] = [
                'label' => (string) (array_get($column, 'view_column_name') ?? $item->label()),
                'value' => $value === null || $text === '' ? exmtrans('common.grid_filter_chip_null') : $text,
            ];
        }
        return $out;
    }

    /**
     * Narrow a query on the listed table to the rows behind the point named by $keys
     * (keys()).
     *
     * @param mixed $query  query on $this->table()'s rows (the data grid's model)
     * @param array<int|string, string|int|float|bool|null> $keys
     */
    public function apply($query, array $keys): void
    {
        $ids = $this->groupRowIds($keys);
        if ($this->relation === null) {
            $query->whereIn(getDBTableName($this->table) . '.id', $ids);
            return;
        }

        $child = getDBTableName($this->grain);
        if ($this->relation->relation_type == RelationType::ONE_TO_MANY) {
            $query->whereIn("$child.parent_id", $ids)->where("$child.parent_type", $this->table->table_name);
        } else {
            $pivot = $this->relation->getRelationName();
            $query->whereIn("$child.id", DB::table($pivot)->select("$pivot.child_id")->whereIn("$pivot.parent_id", $ids));
        }
        // the child's own group columns: the child rows of the point's value only, read by
        // the expression the sub query of the view groups them by
        $columns = $this->groupColumns();
        foreach ($keys as $id => $value) {
            if ($columns[$id]->view_column_table_id == $this->grain->id) {
                $expr = $this->childItem($columns[$id])
                    ->options(['summary' => true, 'group_condition' => $columns[$id]->view_group_condition])
                    ->getGroupByWrapTableColumn(true);
                static::whereExpr($query, $expr, $value);
            }
        }
        // a child row without a value of the measure adds nothing to the point
        $item = $this->childItem($this->measure);
        $expr = \Exment::wrapColumn($item->getTableColumn());
        $query->whereRaw("$expr IS NOT NULL");
        if ($item->isMultipleEnabled()) {
            $query->whereRaw("$expr <> '[]'");
        }
    }

    /**
     * Ids of the view's table whose rows fall into the point: the view's summary query on the
     * box's rows, grouped by the row id besides its group columns, its groups read back. On
     * the view's table, a row without a value of the measure is left out (its per-row
     * measure is NULL; a COUNT, 0).
     *
     * @param array<int|string, string|int|float|bool|null> $keys
     * @return \Illuminate\Database\Query\Builder
     */
    private function groupRowIds(array $keys)
    {
        $db = getDBTableName($this->table);
        $query = ($this->rows)();
        // a query of its own: the view caches the joins its filters made in its search service,
        // and the relations it joins hold the sub query parts of the last query run
        $this->view->resetSearchService();
        $this->forgetRelationTables();
        $this->view->getQuery($query);
        $grouped = $query->toBase();
        // a sub query has no order (SQL Server refuses one)
        $grouped->orders = null;
        $grouped->selectRaw(\Exment::wrapColumn("$db.id") . ' AS ' . \Exment::wrapColumn(static::ID));
        $grouped->groupBy("$db.id");

        $ids = DB::query()->fromSub($grouped, 'chart_detail')->select('chart_detail.' . static::ID);
        $columns = $this->groupColumns();
        foreach ($keys as $id => $value) {
            static::whereExpr($ids, \Exment::wrapColumn('chart_detail.' . $columns[$id]->column_item->sqlAsName()), $value);
        }
        if ($this->relation === null) {
            $measure = \Exment::wrapColumn('chart_detail.' . $this->measure->column_item->sqlAsName());
            $ids->whereRaw((string) $this->measure->view_summary_condition === SummaryCondition::COUNT ? "$measure > 0" : "$measure IS NOT NULL");
        }
        return $ids;
    }

    /**
     * Drop the relations of the view's tables cached for this request (RelationTable::
     * getRelationTables): a summary query adds the columns of a child's sub query to the
     * cached relation, so a second query of the view — the chart's, then the 明細's — would
     * select them twice ("Duplicate column name").
     */
    private function forgetRelationTables(): void
    {
        $tables = collect([$this->table->id]);
        foreach (['custom_view_columns', 'custom_view_summaries', 'custom_view_filters'] as $relation) {
            $tables = $tables->merge(collect($this->view->{$relation})->pluck('view_column_table_id'));
        }
        foreach ($tables->unique()->filter() as $table_id) {
            $table = CustomTable::getEloquent($table_id);
            if ($table !== null) {
                foreach (['1', ''] as $parents) {
                    System::clearRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_TABLE_RELATION_TABLES, $table->table_name, $parents));
                }
            }
        }
    }

    /**
     * A copy of a view column's item reading the listed child table's own rows (the view
     * reads them through a sub query under an alias).
     *
     * @param CustomViewColumn|CustomViewSummary $column
     * @return mixed  the column item
     */
    private function childItem($column)
    {
        $item = static::copyItem($column);
        $item->setUniqueTableName(getDBTableName($this->grain));
        return $item;
    }

    /**
     * A copy of a view column's item, to set options on without touching the view's own.
     *
     * @param CustomViewColumn|CustomViewSummary $column
     * @return mixed  the column item
     */
    private static function copyItem($column)
    {
        $item = $column->column_item;
        return clone $item;
    }

    /**
     * $expr equals $value, NULL for a group without a value.
     *
     * @param mixed $query
     * @param string|int|float|bool|null $value
     */
    private static function whereExpr($query, string $expr, $value): void
    {
        if ($value === null) {
            $query->whereRaw("$expr IS NULL");
        } else {
            $query->whereRaw("$expr = ?", [$value]);
        }
    }
}
