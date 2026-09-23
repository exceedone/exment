<?php

namespace Exceedone\Exment\DataItems\Grid;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\ColumnItems\GridCellStyle;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * Gantt view.
 *
 * Bars from a start date column to an end date column, one row per record,
 * grouped by a select or select_table column (typically a milestone table).
 * Like the kanban view, the server builds a json payload and the browser
 * draws it - which keeps zooming, collapsing and re-laying out free of round
 * trips. The chart is read only in this version: a click opens the record.
 */
class GanttGrid extends GridBase
{
    /**
     * Group key of records without a group value.
     */
    public const EMPTY_KEY = '__exment_gantt_empty__';

    /**
     * Colors given to bar color values, by option order.
     */
    protected const PALETTE = [
        '#c0392b', '#e67e22', '#f1c40f', '#27ae60', '#95a5a6',
        '#3c8dbc', '#8e44ad', '#16a085', '#d35400', '#2980b9',
    ];

    /**
     * Colors given to people, by name hash. Same list as the kanban view, so
     * one person wears one color across both screens.
     */
    protected const AVATAR_PALETTE = [
        '#16a085', '#3c8dbc', '#e67e22', '#8e44ad', '#2980b9',
        '#c0392b', '#27ae60', '#d35400', '#7f8c8d', '#1abc9c',
    ];

    /**
     * Bar color when no color column is set.
     */
    protected const DEFAULT_BAR_COLOR = '#3c8dbc';

    // @phpstan-ignore-next-line
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    // @phpstan-ignore-next-line
    public function grid($callback = null)
    {
        // same buttons in the same order as the data grid toolbar
        $tools = [];
        $this->setViewMenuButton($tools, true);
        $this->setTableMenuButton($tools, true);
        $this->setNewButton($tools, true);

        $start_column = $this->getDateColumnById($this->custom_view->gantt_start_column_id);
        $end_column = $this->getDateColumnById($this->custom_view->gantt_end_column_id);

        if (!isset($start_column) || !isset($end_column)) {
            return view('exment::widgets.gantt', [
                'embed' => $this->isEmbed(),
                'tools' => $tools,
                'error' => exmtrans('custom_view.message.gantt_no_date_setting'),
                'chart' => [],
                'over_limit' => false,
                'max_count' => 0,
            ]);
        }

        $max_count = $this->getMaxCount();
        $query = $this->newChartQuery();
        $records = $query->take($max_count + 1)->get();
        $over_limit = ($records->count() > $max_count);
        if ($over_limit) {
            $records = $records->take($max_count);
        }

        return view('exment::widgets.gantt', [
            'embed' => $this->isEmbed(),
            'tools' => $tools,
            'error' => null,
            'chart' => $this->buildChart($records, $start_column, $end_column),
            'over_limit' => $over_limit,
            'max_count' => $max_count,
        ]);
    }

    /**
     * The chart query: the view's filters, then the embed filter.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function newChartQuery()
    {
        $query = $this->custom_table->getValueQuery();
        $this->custom_view->resetSearchService();
        $this->custom_view->filterSortModel($query);
        $this->applyEmbedFilter($query);

        return $query;
    }

    /**
     * Build the whole payload the browser draws the chart from.
     *
     * @param \Illuminate\Support\Collection $records
     * @param CustomColumn $start_column
     * @param CustomColumn $end_column
     * @return array<string, mixed>
     */
    protected function buildChart($records, $start_column, $end_column)
    {
        $color_column = $this->getSelectColumnById($this->custom_view->gantt_color_column_id);
        $progress_column = $this->getNumberColumnById($this->custom_view->gantt_progress_column_id);
        $group_column = $this->getGroupColumn();
        $parent_column = $this->getParentColumn();
        $assignee_column = $this->getUserColumnById($this->custom_view->gantt_assignee_column_id);

        $rows = [];
        $assignee_ids = [];
        $group_ids = [];
        foreach ($records as $record) {
            $start = $this->rawDate($record, $start_column);
            $end = $this->rawDate($record, $end_column);

            $group_key = '';
            if (isset($group_column)) {
                $raw = array_get($record->value, $group_column->column_name);
                $raw = is_array($raw) ? array_first($raw) : $raw;
                $group_key = is_nullorempty($raw) ? '' : strval($raw);
                if ($group_key !== '' && $group_column->column_type == ColumnType::SELECT_TABLE) {
                    $group_ids[] = $group_key;
                }
            }

            $parent = null;
            if (isset($parent_column)) {
                $raw = array_get($record->value, $parent_column->column_name);
                $raw = is_array($raw) ? array_first($raw) : $raw;
                $parent = is_nullorempty($raw) ? null : strval($raw);
            }

            $assignee = null;
            if (isset($assignee_column)) {
                $raw = array_get($record->value, $assignee_column->column_name);
                $raw = is_array($raw) ? array_first($raw) : $raw;
                if (!is_nullorempty($raw)) {
                    $assignee = strval($raw);
                    $assignee_ids[] = $assignee;
                }
            }

            $progress = null;
            if (isset($progress_column)) {
                $raw = array_get($record->value, $progress_column->column_name);
                if (!is_nullorempty($raw) && is_numeric($raw)) {
                    $progress = max(0, min(100, floatval($raw)));
                }
            }

            $color_key = '';
            if (isset($color_column)) {
                $raw = array_get($record->value, $color_column->column_name);
                $raw = is_array($raw) ? array_first($raw) : $raw;
                $color_key = is_nullorempty($raw) ? '' : strval($raw);
            }

            $rows[] = [
                'id' => $record->id,
                'label' => strval($record->getLabel()),
                'url' => $record->getUrl(),
                'start' => $start,
                'end' => $end,
                'group' => $group_key,
                'parent' => $parent,
                'assignee' => $assignee,
                'progress' => $progress,
                'color' => $color_key,
            ];
        }

        list($colors, $legend) = $this->buildColors($color_column);

        return [
            'table' => $this->custom_table->table_name,
            'data_url' => admin_url('data', [$this->custom_table->table_name]),
            'today' => \Carbon\Carbon::today()->format('Y-m-d'),
            'rows' => $rows,
            'groups' => $this->buildGroups($group_column, $rows),
            'group_label' => isset($group_column) ? $group_column->column_view_name : null,
            'assignees' => $this->buildAssignees($assignee_column, array_unique($assignee_ids)),
            'colors' => $colors,
            'legend' => $legend,
            'features' => [
                'progress' => isset($progress_column),
                'assignee' => isset($assignee_column),
                'parent' => isset($parent_column),
            ],
        ];
    }

    /**
     * A raw stored date, cut down to Y-m-d. A datetime column stores
     * "Y-m-d H:i:s", a date column "Y-m-d"; the chart wants days.
     *
     * @param \Exceedone\Exment\Model\CustomValue $record
     * @param CustomColumn $custom_column
     * @return string|null
     */
    protected function rawDate($record, $custom_column)
    {
        $raw = array_get($record->value, $custom_column->column_name);
        if (is_nullorempty($raw)) {
            return null;
        }
        $raw = substr(strval($raw), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }

    /**
     * The row groups, in the order the chart draws them.
     *
     * A select column follows its own option order. A select_table group
     * (typically a milestone) is ordered by its own first date column - the
     * closest deadline first, the undated ones last - and that date is also
     * handed over, so the chart can draw the deadline diamond.
     *
     * @param CustomColumn|null $group_column
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function buildGroups($group_column, array $rows)
    {
        $used = collect($rows)->pluck('group')->unique()->values();
        if (!isset($group_column)) {
            return [['key' => '', 'label' => null, 'due' => null]];
        }

        $groups = [];
        if ($group_column->column_type == ColumnType::SELECT_TABLE) {
            $target_table = $group_column->select_target_table;
            $due_column = $this->firstDateColumn($target_table);
            foreach ($used as $key) {
                if ($key === '') {
                    continue;
                }
                $target = isset($target_table) ? $target_table->getValueModel()->find($key) : null;
                $groups[] = [
                    'key' => strval($key),
                    'label' => isset($target) ? strval($target->getLabel()) : strval($key),
                    'due' => (isset($target) && isset($due_column))
                        ? substr(strval(array_get($target->value, $due_column->column_name, '')), 0, 10) ?: null
                        : null,
                ];
            }
            usort($groups, function ($a, $b) {
                if (is_null($a['due']) != is_null($b['due'])) {
                    return is_null($a['due']) ? 1 : -1;
                }

                return strcmp(strval($a['due']), strval($b['due'])) ?: strcmp($a['label'], $b['label']);
            });
        } else {
            foreach ($this->listedOptions($group_column) as $key => $label) {
                if (!$used->contains(strval($key))) {
                    continue;
                }
                $groups[] = ['key' => strval($key), 'label' => strval($label), 'due' => null];
            }
        }

        if ($used->contains('')) {
            $groups[] = ['key' => '', 'label' => exmtrans('custom_view.gantt_no_group'), 'due' => null];
        }

        return $groups;
    }

    /**
     * id => {name, color, initial} of everyone assigned on the chart.
     *
     * @param CustomColumn|null $assignee_column
     * @param array<int, string> $ids
     * @return array<string, array<string, string>>
     */
    protected function buildAssignees($assignee_column, array $ids)
    {
        if (!isset($assignee_column) || empty($ids)) {
            return [];
        }
        $user_table = CustomTable::getEloquent(SystemTableName::USER);
        if (!isset($user_table)) {
            return [];
        }

        $assignees = [];
        foreach ($user_table->getValueModel()->find($ids) as $user) {
            $name = strval($user->getLabel());
            $assignees[strval($user->id)] = [
                'name' => $name,
                'initial' => mb_substr(trim($name), 0, 1),
                'color' => static::AVATAR_PALETTE[abs(crc32($name)) % count(static::AVATAR_PALETTE)],
            ];
        }

        return $assignees;
    }

    /**
     * Fixed color per bar color value, plus the legend drawn above the chart.
     *
     * The colors set on the column's own data list settings win, so a value
     * never wears one color on the list and another on the chart.
     *
     * @param CustomColumn|null $color_column
     * @return array{0: array<string, string>, 1: array<int, array<string, string>>}
     */
    protected function buildColors($color_column)
    {
        if (!isset($color_column)) {
            return [[], []];
        }

        $picked = GridCellStyle::valueColorsOf($color_column);
        $colors = [];
        $legend = [];
        $index = 0;
        foreach ($this->listedOptions($color_column) as $key => $label) {
            $color = array_get($picked, strval($key) . '.color');
            $color = $color ?: static::PALETTE[$index % count(static::PALETTE)];
            $colors[strval($key)] = $color;
            $legend[] = ['key' => strval($key), 'label' => strval($label), 'color' => $color];
            $index++;
        }

        return [$colors, $legend];
    }

    /* ------------------------------------------------------------------ */
    /* column pickers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * key => label choices of a select / select_valtext column.
     *
     * @param CustomColumn|null $custom_column
     * @return array<int|string, string>
     */
    protected function listedOptions($custom_column)
    {
        if (!isset($custom_column) || !in_array($custom_column->column_type, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT])) {
            return [];
        }

        return $custom_column->createSelectOptions();
    }

    /**
     * @return CustomColumn|null
     */
    protected function getGroupColumn()
    {
        $custom_column = $this->getColumnById($this->custom_view->gantt_group_column_id);
        if (!isset($custom_column)) {
            return null;
        }
        if (!in_array($custom_column->column_type, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT, ColumnType::SELECT_TABLE])) {
            return null;
        }
        // a multi-value column would draw one record in several groups
        if ($custom_column->isMultipleEnabled()) {
            return null;
        }

        return $custom_column;
    }

    /**
     * The self-reference column that nests one record under another.
     *
     * @return CustomColumn|null
     */
    protected function getParentColumn()
    {
        $custom_column = $this->getColumnById($this->custom_view->gantt_parent_column_id);
        if (!isset($custom_column) || $custom_column->column_type != ColumnType::SELECT_TABLE) {
            return null;
        }
        $target = $custom_column->select_target_table;
        if (!isset($target) || $target->id != $this->custom_table->id) {
            return null;
        }

        return $custom_column;
    }

    /**
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getSelectColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || !in_array($custom_column->column_type, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT])) {
            return null;
        }

        return $custom_column;
    }

    /**
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getNumberColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || !in_array($custom_column->column_type, [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY])) {
            return null;
        }

        return $custom_column;
    }

    /**
     * A user column: the user type itself, or a select_table aimed at users.
     *
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getUserColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column)) {
            return null;
        }
        if ($custom_column->column_type == ColumnType::USER) {
            return $custom_column;
        }
        if ($custom_column->column_type == ColumnType::SELECT_TABLE) {
            $target = $custom_column->select_target_table;
            if (isset($target) && $target->table_name == SystemTableName::USER) {
                return $custom_column;
            }
        }

        return null;
    }

    /**
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getDateColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || !ColumnType::isDate(array_get($custom_column, 'column_type'))) {
            return null;
        }

        return $custom_column;
    }

    /**
     * Any custom column belonging to this table.
     *
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getColumnById($column_id)
    {
        if (is_nullorempty($column_id)) {
            return null;
        }

        $custom_column = CustomColumn::getEloquent($column_id);
        if (!isset($custom_column) || $custom_column->custom_table_id != $this->custom_table->id) {
            return null;
        }

        return $custom_column;
    }

    /**
     * The deadline column of a group table (a milestone table's own due date),
     * for the deadline diamond. A name that reads as a deadline wins; a table
     * with no such name keeps its last date column - "start, end" layouts put
     * the deadline last.
     *
     * @param CustomTable|null $custom_table
     * @return CustomColumn|null
     */
    protected function firstDateColumn($custom_table)
    {
        if (!isset($custom_table)) {
            return null;
        }
        $last = null;
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (!ColumnType::isDate(array_get($custom_column, 'column_type'))) {
                continue;
            }
            if (preg_match('/due|end|limit|deadline|期限/i', strval($custom_column->column_name))) {
                return $custom_column;
            }
            $last = $custom_column;
        }

        return $last;
    }

    /**
     * Max records drawn on the chart.
     *
     * @return int
     */
    protected function getMaxCount()
    {
        $max_count = intval($this->custom_view->gantt_max_count);
        if ($max_count < 10 || $max_count > 2000) {
            return 500;
        }

        return $max_count;
    }

    /* ------------------------------------------------------------------ */
    /* view create form                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Whether a gantt chart can be made for this table at all: it needs a
     * date column for the bars to start from.
     *
     * @param CustomTable $custom_table
     * @return bool
     */
    public static function canCreateChart($custom_table)
    {
        return !is_nullorempty(static::getColumnOptionsByType($custom_table, ColumnType::COLUMN_TYPE_DATE()));
    }

    /**
     * Select options limited to the given column types.
     *
     * @param CustomTable $custom_table
     * @param array<int, string> $column_types
     * @return array<int|string, string>
     */
    protected static function getColumnOptionsByType($custom_table, array $column_types)
    {
        $options = [];
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (!in_array(array_get($custom_column, 'column_type'), $column_types)) {
                continue;
            }
            $options[$custom_column->id] = $custom_column->column_view_name;
        }

        return $options;
    }

    /**
     * Group column choices: single-value select, select_valtext, select_table.
     *
     * @param CustomTable $custom_table
     * @return array<int|string, string>
     */
    protected static function getGroupColumnOptions($custom_table)
    {
        $options = [];
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (!in_array(array_get($custom_column, 'column_type'), [ColumnType::SELECT, ColumnType::SELECT_VALTEXT, ColumnType::SELECT_TABLE])) {
                continue;
            }
            if ($custom_column->isMultipleEnabled()) {
                continue;
            }
            $options[$custom_column->id] = $custom_column->column_view_name;
        }

        return $options;
    }

    /**
     * Self-reference column choices for the parent field.
     *
     * @param CustomTable $custom_table
     * @return array<int|string, string>
     */
    protected static function getParentColumnOptions($custom_table)
    {
        $options = [];
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (array_get($custom_column, 'column_type') != ColumnType::SELECT_TABLE) {
                continue;
            }
            $target = $custom_column->select_target_table;
            if (!isset($target) || $target->id != $custom_table->id) {
                continue;
            }
            $options[$custom_column->id] = $custom_column->column_view_name;
        }

        return $options;
    }

    /**
     * User column choices for the assignee field.
     *
     * @param CustomTable $custom_table
     * @return array<int|string, string>
     */
    protected static function getUserColumnOptions($custom_table)
    {
        $options = [];
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            $column_type = array_get($custom_column, 'column_type');
            $is_user = ($column_type == ColumnType::USER);
            if (!$is_user && $column_type == ColumnType::SELECT_TABLE) {
                $target = $custom_column->select_target_table;
                $is_user = isset($target) && $target->table_name == SystemTableName::USER;
            }
            if (!$is_user) {
                continue;
            }
            $options[$custom_column->id] = $custom_column->column_view_name;
        }

        return $options;
    }

    /**
     * The view settings form.
     *
     * @param string $view_kind_type
     * @param \ExmentAdminCore\Admin\Form $form
     * @param CustomTable $custom_table
     * @param array<string, mixed> $options
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table, array $options = [])
    {
        static::setViewInfoboxFields($form);

        $date_options = static::getColumnOptionsByType($custom_table, ColumnType::COLUMN_TYPE_DATE());
        $select_options = [];
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (in_array(array_get($custom_column, 'column_type'), [ColumnType::SELECT, ColumnType::SELECT_VALTEXT])) {
                $select_options[$custom_column->id] = $custom_column->column_view_name;
            }
        }
        $number_options = static::getColumnOptionsByType($custom_table, [
            ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY,
        ]);

        // ------------------------------------------------- basic settings --
        $form->exmheader(exmtrans('common.basic_setting'))->hr();

        $form->select('gantt_start_column_id', exmtrans("custom_view.gantt_start_column"))
            ->required()
            ->options($date_options)
            ->help(exmtrans("custom_view.help.gantt_start_column"));

        $form->select('gantt_end_column_id', exmtrans("custom_view.gantt_end_column"))
            ->required()
            ->options($date_options)
            ->help(exmtrans("custom_view.help.gantt_end_column"));

        $form->select('gantt_color_column_id', exmtrans("custom_view.gantt_color_column"))
            ->options($select_options)
            ->help(exmtrans("custom_view.help.gantt_color_column"));

        $form->select('gantt_progress_column_id', exmtrans("custom_view.gantt_progress_column"))
            ->options($number_options)
            ->help(exmtrans("custom_view.help.gantt_progress_column"));

        // ---------------------------------------------- advanced settings --
        $form->exmheader(exmtrans('custom_view.gantt_advanced_setting'))->hr();

        $form->select('gantt_group_column_id', exmtrans("custom_view.gantt_group_column"))
            ->options(static::getGroupColumnOptions($custom_table))
            ->help(exmtrans("custom_view.help.gantt_group_column"));

        $form->select('gantt_parent_column_id', exmtrans("custom_view.gantt_parent_column"))
            ->options(static::getParentColumnOptions($custom_table))
            ->help(exmtrans("custom_view.help.gantt_parent_column"));

        $form->select('gantt_assignee_column_id', exmtrans("custom_view.gantt_assignee_column"))
            ->options(static::getUserColumnOptions($custom_table))
            ->help(exmtrans("custom_view.help.gantt_assignee_column"));

        $form->number('gantt_max_count', exmtrans("custom_view.gantt_max_count"))
            ->default(500)
            ->min(10)
            ->max(2000)
            ->help(exmtrans("custom_view.help.gantt_max_count"));

        $custom_view = array_get($options, 'custom_view');
        static::setSortFields($form, $custom_table, false, $custom_view);
        static::setFilterFields($form, $custom_table, false, $custom_view);
    }
}
