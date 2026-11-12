<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;

/**
 * Runtime 期間の単位 of a chart box (`pu` on the box request), picked in the box menu: the date
 * column on the chart's X axis grouped by day, week, month, quarter or year for this render
 * only, without changing the aggregate view (Excel's グループ化 of a pivot's date field).
 *
 * The groups come from the view's own query: apply() swaps the column's group condition in
 * memory while the box runs it and puts it back afterwards, so the database groups the rows
 * (GroupCondition + the Grammar's date format) — sort, 表示方法, 目標線, the 明細 and the Excel
 * download all read the groups as drawn. Remembered per user like the chart type and the sort
 * (ChartItem::toolbarState).
 *
 * Offered on a date / datetime column (the created / updated dates too) that is a time line:
 * not grouped, or grouped by year, month or day of a year (y / ym / ymd). A view grouping by
 * month of any year (m), day of a month (d) or weekday (w) folds the years into one cycle on
 * purpose: no switch. A unit picked that the view sets already is no switch.
 */
final class ChartPeriod
{
    /** the request value */
    public const PARAM = 'pu';

    /** the units a viewer can pick, in the menu's order: 日 / 週 / 月 / 四半期 / 年 */
    public const UNITS = [GroupCondition::YMD, GroupCondition::YW, GroupCondition::YM, GroupCondition::YQ, GroupCondition::Y];

    /** @var CustomView|null */
    private $custom_view;

    /** @var CustomViewColumn|null the date group column switched, null when none can be */
    private $column;

    /** @var string|null the unit the view sets, as one of UNITS (a date not grouped: by day); null for a datetime not grouped */
    private $configured;

    /** @var string|null the unit picked when it differs from the view's, else null */
    private $picked;

    /**
     * @param CustomView|null $custom_view  the box's aggregate view
     * @param mixed $column  the group column to switch (ChartPeriod::columnOf), or null
     * @param mixed $requested  `pu` of the request
     */
    public function __construct($custom_view, $column, $requested)
    {
        if (!$custom_view instanceof CustomView || !$column instanceof CustomViewColumn || !static::switchable($column)) {
            return;
        }
        $this->custom_view = $custom_view;
        $this->column = $column;
        $condition = static::conditionOf($column);
        if ($condition === null) {
            // a date not grouped is grouped by its day already; a datetime by the second
            $item = $column->column_item;
            $condition = is_object($item) && method_exists($item, 'isDateTime') && $item->isDateTime() ? null : GroupCondition::YMD;
        }
        $this->configured = $condition;
        $requested = static::normalize($requested);
        if ($requested !== null && $requested !== $this->configured) {
            $this->picked = $requested;
        }
    }

    /**
     * The group column of $columns a chart's 期間の単位 switches: the first that is a time line
     * (switchable); null when none is.
     *
     * @param iterable<mixed> $columns  the view columns labelling a point (ChartItem::xViewColumns)
     * @return CustomViewColumn|null
     */
    public static function columnOf($columns): ?CustomViewColumn
    {
        foreach ($columns as $column) {
            if ($column instanceof CustomViewColumn && static::switchable($column)) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Whether a group column can be grouped by another unit: a date / datetime (not a time)
     * grouped by y / ym / ymd, or not at all.
     */
    public static function switchable(CustomViewColumn $column): bool
    {
        $item = $column->column_item;
        if (!is_object($item) || !method_exists($item, 'isDate') || !$item->isDate()) {
            return false;
        }
        $condition = static::conditionOf($column);
        return $condition === null || in_array($condition, static::UNITS, true);
    }

    /**
     * `pu` in its own form (one of UNITS), or null when it is not one.
     *
     * @param mixed $pu
     */
    public static function normalize($pu): ?string
    {
        return is_string($pu) && in_array($pu, static::UNITS, true) ? $pu : null;
    }

    /**
     * Whether the box menu offers the switch.
     */
    public function available(): bool
    {
        return $this->column !== null;
    }

    /**
     * Whether the chart is grouped by a unit other than the view's.
     */
    public function switched(): bool
    {
        return $this->picked !== null;
    }

    /**
     * The unit the chart is grouped by; null for a datetime the view does not group.
     */
    public function current(): ?string
    {
        return $this->picked ?? $this->configured;
    }

    public function configured(): ?string
    {
        return $this->configured;
    }

    /**
     * The group column switched, null when none can be.
     */
    public function column(): ?CustomViewColumn
    {
        return $this->column;
    }

    /**
     * Whether $column is the group column the chart groups by the picked unit.
     *
     * @param mixed $column
     */
    public function switches($column): bool
    {
        return $this->switched() && is_object($column) && data_get($column, 'id') == $this->column->id;
    }

    /**
     * The name of a unit: 日, 週, 月, 四半期, 年; the view's own grouping of a datetime: 日時.
     */
    public static function label(?string $unit): string
    {
        return (string) exmtrans('dashboard.chart_period.units.' . ($unit ?? 'none'));
    }

    /**
     * A label of the switched column naming the unit: 受注日（四半期）; else as it is.
     *
     * @param mixed $column  a group column
     * @param mixed $label  its label
     * @return mixed
     */
    public function decorateColumn($column, $label)
    {
        if (!$this->switches($column)) {
            return $label;
        }
        return (string) exmtrans('dashboard.chart_show_as.decorated', (string) $label, static::label($this->picked));
    }

    /**
     * Run $query (the box's data query, a 明細's) with the column grouped by current(), then
     * put the view's setting back — on the view's own group columns, which its query reads
     * (SummaryGrid::getQuery), and on the option their item was given meanwhile.
     *
     * @template T
     * @param callable(): T $query
     * @return T
     */
    public function apply(callable $query)
    {
        if (!$this->switched()) {
            return $query();
        }
        $saved = [];
        foreach ($this->columnInstances() as $column) {
            $item = $column->column_item;
            $saved[] = [$column, $column->view_group_condition, $item, is_object($item) && method_exists($item, 'options') ? array_get($item->options(), 'group_condition') : null];
            $column->view_group_condition = $this->picked;
        }
        try {
            return $query();
        } finally {
            foreach ($saved as [$column, $condition, $item, $option]) {
                $column->view_group_condition = $condition;
                if (is_object($item) && method_exists($item, 'options')) {
                    $item->options(['group_condition' => $option]);
                }
            }
        }
    }

    /**
     * The view's group columns that are the switched one: the ones its query reads, those its
     * relation holds, and the column itself should it be another instance.
     *
     * @return CustomViewColumn[]
     */
    private function columnInstances(): array
    {
        $instances = [];
        foreach ([$this->custom_view->custom_view_columns_cache, $this->custom_view->custom_view_columns] as $columns) {
            foreach ($columns as $column) {
                if ($column->id == $this->column->id) {
                    $instances[spl_object_id($column)] = $column;
                }
            }
        }
        $instances[spl_object_id($this->column)] = $this->column;
        return array_values($instances);
    }

    /**
     * The group condition the view sets on $column, or null.
     */
    private static function conditionOf(CustomViewColumn $column): ?string
    {
        $condition = $column->view_group_condition;
        return is_scalar($condition) && $condition !== '' ? (string) $condition : null;
    }
}
