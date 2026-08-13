<?php

namespace Exceedone\Exment\DataItems\Grid;

use ExmentAdminCore\Admin\Form;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;

/**
 * Kanban view.
 *
 * The board is drawn by the browser from a json payload built here, the same
 * way the design mockup does it. Rendering client side is what makes the live
 * features possible - re-grouping, swimlane switching, filtering, multi select
 * and the detail drawer all work without a round trip.
 *
 * Writes go through the existing webapi endpoints, so permission, validation,
 * revision and notify behaviour stay exactly the same as a normal edit.
 */
class KanbanGrid extends GridBase
{
    /**
     * Board column key used for records whose group value is empty.
     */
    public const EMPTY_KEY = '__exment_kanban_empty__';

    /**
     * What the board columns are made of.
     */
    public const SOURCE_COLUMN = 'column';
    public const SOURCE_WORKFLOW = 'workflow';

    /**
     * Pseudo column name of the workflow status, used where a real column name
     * would go. It can never clash with a real column: Exment column names are
     * lower alphanumeric plus underscore, and never start with two underscores.
     */
    public const WORKFLOW_KEY = '__workflow_status__';

    /**
     * Prefix of a stored WIP / done setting value.
     *   "12::open"  -> value "open" of custom column 12
     *   "wf::3"     -> workflow status 3
     */
    public const WORKFLOW_PREFIX = 'wf';

    /**
     * Card field placement inside a card.
     */
    public const POS_HEADER = 'header';
    public const POS_META = 'meta';
    public const POS_META2 = 'meta2';
    public const POS_FOOT = 'foot';

    /**
     * Card field appearance.
     */
    public const STYLE_AUTO = 'auto';
    public const STYLE_TEXT = 'text';
    public const STYLE_TAG = 'tag';
    public const STYLE_PILL = 'pill';
    public const STYLE_DOT = 'dot';
    public const STYLE_LVL = 'lvl';
    public const STYLE_STATE = 'state';
    public const STYLE_CHIP = 'chip';
    public const STYLE_POINT = 'point';
    public const STYLE_FLAG = 'flag';
    public const STYLE_AVATAR = 'avatar';
    public const STYLE_ICONTEXT = 'icontext';

    /**
     * Colors given to select values, by option order.
     */
    protected const PALETTE = [
        '#c0392b', '#e67e22', '#f1c40f', '#27ae60', '#95a5a6',
        '#3c8dbc', '#8e44ad', '#16a085', '#d35400', '#2980b9',
    ];

    /**
     * Colors given to people, by name hash.
     */
    protected const AVATAR_PALETTE = [
        '#16a085', '#3c8dbc', '#e67e22', '#8e44ad', '#2980b9',
        '#c0392b', '#27ae60', '#d35400', '#7f8c8d', '#1abc9c',
    ];

    // @phpstan-ignore-next-line
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    // @phpstan-ignore-next-line
    public function grid($callback = null)
    {
        $tools = [];
        $this->setNewButton($tools);
        $this->setTableMenuButton($tools);
        $this->setViewMenuButton($tools);

        // The view is unusable until the board columns can be built. Show the
        // reason instead of an empty board, so the user knows where to go.
        $group_column = null;
        $workflow = null;
        if ($this->getSource() == static::SOURCE_WORKFLOW) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
            $error = isset($workflow) ? null : exmtrans('custom_view.message.kanban_no_workflow');
        } else {
            $group_column = $this->getGroupColumn();
            $error = isset($group_column) ? null : exmtrans('custom_view.message.kanban_no_group_column');
        }

        if (isset($error)) {
            return view('exment::widgets.kanban', [
                'tools' => $tools,
                'error' => $error,
                'board' => [],
                'over_limit' => false,
                'max_count' => 0,
            ]);
        }

        $max_count = $this->getMaxCount();
        $query = $this->custom_table->getValueQuery();
        $this->custom_view->filterSortModel($query);
        if (isset($workflow)) {
            // without this every card would run its own query for the status
            $query->with(['workflow_value', 'workflow_value.workflow_status']);
        }
        // take one extra row to detect that the board is truncated
        $records = $query->take($max_count + 1)->get();
        $over_limit = ($records->count() > $max_count);
        if ($over_limit) {
            $records = $records->take($max_count);
        }

        return view('exment::widgets.kanban', [
            'tools' => $tools,
            'error' => null,
            'board' => $this->buildBoard($group_column, $workflow, $records),
            'over_limit' => $over_limit,
            'max_count' => $max_count,
        ]);
    }


    /**
     * Build the whole payload the browser draws the board from.
     *
     * @param CustomColumn|null $group_column board columns come from this column
     * @param Workflow|null $workflow ... or from this workflow's statuses
     * @param \Illuminate\Support\Collection $records
     * @return array<string, mixed>
     */
    protected function buildBoard($group_column, $workflow, $records)
    {
        $is_workflow = isset($workflow);
        $group_name = $is_workflow ? static::WORKFLOW_KEY : $group_column->column_name;
        $statuses = $is_workflow ? $this->buildWorkflowStatuses($workflow) : [];

        $groupables = $this->getGroupableColumns();
        $swimlane_column = $this->getSwimlaneColumn();
        $title_column = $this->getColumnById($this->custom_view->kanban_title_column_id);
        $assignee_column = $this->getColumnById($this->custom_view->kanban_assignee_column_id);
        $limit_column = $this->getDateColumnById($this->custom_view->kanban_limit_column_id);
        $age_column = $this->getDateColumnById($this->custom_view->kanban_age_column_id);
        $ai_column = $this->getSelectColumn($this->custom_view->kanban_ai_column_id);
        $wip_column = $this->getNumberColumnById($this->custom_view->kanban_wip_column_id);

        $card_fields = $this->getCardFields();

        // every column whose raw value the browser needs
        $value_columns = [];
        foreach ($groupables as $custom_column) {
            $value_columns[$custom_column->column_name] = $custom_column;
        }
        foreach ([$title_column, $assignee_column, $limit_column, $age_column, $ai_column, $wip_column] as $custom_column) {
            if (isset($custom_column)) {
                $value_columns[$custom_column->column_name] = $custom_column;
            }
        }
        foreach ($card_fields as $card_field) {
            if (isset($card_field['custom_column'])) {
                $value_columns[$card_field['custom_column']->column_name] = $card_field['custom_column'];
            }
        }

        $cards = $this->buildCards($records, $value_columns, $card_fields, $title_column, $workflow, $statuses);

        // the workflow status behaves like one more groupable/filterable column
        $groupable_meta = $this->buildColumnMeta($groupables);
        $filter_meta = $this->buildColumnMeta($this->getFilterColumns($groupables, $assignee_column, $card_fields));
        $colors = $this->buildColors($value_columns);
        if ($is_workflow) {
            $status_meta = [
                'name' => static::WORKFLOW_KEY,
                'label' => exmtrans('workflow.status'),
                'options' => collect($statuses)->map(function ($status) {
                    return ['key' => $status['key'], 'label' => $status['label']];
                })->toArray(),
            ];
            array_unshift($groupable_meta, $status_meta);
            array_unshift($filter_meta, $status_meta);

            $map = [];
            $index = 0;
            foreach ($statuses as $status) {
                $map[$status['key']] = static::PALETTE[$index % count(static::PALETTE)];
                $index++;
            }
            $colors[static::WORKFLOW_KEY] = $map;
        }

        return [
            'editable' => $this->isEditable(),
            'creatable' => $this->isCreatable(),
            'update_url' => admin_url('webapi/data', [$this->custom_table->table_name]),
            'create_url' => admin_url('webapi/data', [$this->custom_table->table_name]),
            'data_url' => admin_url('data', [$this->custom_table->table_name]),
            'now' => \Carbon\Carbon::now()->format('Y-m-d H:i:s'),

            'source' => $is_workflow ? static::SOURCE_WORKFLOW : static::SOURCE_COLUMN,
            'workflow_key' => static::WORKFLOW_KEY,
            'workflow_start' => Define::WORKFLOW_START_KEYNAME,
            'statuses' => $statuses,

            'group_column' => $group_name,
            'swimlane_column' => isset($swimlane_column) ? $swimlane_column->column_name : '',
            'title_column' => isset($title_column) ? $title_column->column_name : '',
            'assignee_column' => isset($assignee_column) ? $assignee_column->column_name : '',
            'limit_column' => isset($limit_column) ? $limit_column->column_name : '',
            'age_column' => isset($age_column) ? $age_column->column_name : '',
            'ai_column' => isset($ai_column) ? $ai_column->column_name : '',
            'label_column' => $this->getQuickAddColumnName($title_column),

            // a lane is not always measured in cards, and "close to the
            // deadline" / "sitting too long" mean different things per field
            'wip_column' => isset($wip_column) ? $wip_column->column_name : '',
            'limit_warn' => $this->getLimitWarn(),
            'age_steps' => $this->getAgeSteps(),

            'groupables' => $groupable_meta,
            'filters' => $filter_meta,
            'assignees' => isset($assignee_column) ? $this->buildOptions($assignee_column) : [],
            'colors' => $colors,

            'wip' => $this->getWipLimits($group_column, $workflow),
            'done_keys' => $this->getDoneKeys($group_column, $workflow, $statuses),
            'features' => [
                'kpi' => boolval($this->custom_view->kanban_kpi),
                'quickadd' => boolval($this->custom_view->kanban_quickadd) && $this->isCreatable(),
                // one record at a time is the only safe way through a workflow,
                // so a bulk status change is offered for plain columns only
                'bulk' => boolval($this->custom_view->kanban_bulk) && $this->isEditable(),
                'bulk_move' => boolval($this->custom_view->kanban_bulk) && $this->isEditable() && !$is_workflow,
                'drawer' => boolval($this->custom_view->kanban_drawer),
                'ai' => isset($assignee_column) && isset($ai_column) && $this->isEditable(),
            ],
            'empty_key' => static::EMPTY_KEY,
            'empty_label' => exmtrans('custom_view.kanban_empty_label'),
            'cards' => $cards,
        ];
    }


    /**
     * Board columns of a workflow: the start status, then every status in order.
     *
     * @param Workflow $workflow
     * @return array<int, array<string, mixed>>
     */
    protected function buildWorkflowStatuses($workflow)
    {
        $statuses = [[
            'key' => Define::WORKFLOW_START_KEYNAME,
            'label' => strval($workflow->start_status_name),
            'done' => false,
        ]];

        foreach ($workflow->workflow_statuses_cache as $workflow_status) {
            $statuses[] = [
                'key' => strval($workflow_status->id),
                'label' => strval($workflow_status->status_name),
                'done' => boolval($workflow_status->completed_flg),
            ];
        }

        return $statuses;
    }


    /**
     * Where one record stands in the workflow, and where it may go from there.
     *
     * A move is offered only when an action really changes the status and the
     * logged in user may run it. Everything else - a rejected condition, a
     * missing authority, an approval that still needs other people - keeps the
     * card where it is, exactly like the record detail screen does.
     *
     * @param \Exceedone\Exment\Model\CustomValue $record
     * @param array<int, array<string, mixed>> $statuses
     * @param bool $with_actions whether the moves are needed at all
     * @return array<string, mixed>
     */
    protected function buildWorkflowCard($record, array $statuses, bool $with_actions)
    {
        $workflow_status = $record->workflow_status;
        $status_key = isset($workflow_status) ? strval($workflow_status->id) : Define::WORKFLOW_START_KEYNAME;

        $label = $status_key;
        foreach ($statuses as $status) {
            if ($status['key'] === $status_key) {
                $label = $status['label'];
            }
        }

        $result = [
            'status' => $status_key,
            'status_name' => $label,
            'locked' => boolval($record->lockedWorkflow()),
            'actions' => [],
            'moves' => [],
        ];

        if (!$with_actions) {
            return $result;
        }

        foreach ($record->getWorkflowActions(true) as $workflow_action) {
            $status_to = $workflow_action->getStatusToId($record);
            $status_to = is_nullorempty($status_to) ? '' : strval($status_to);
            $changes = ($status_to !== '' && $status_to !== $status_key);

            $result['actions'][] = [
                'id' => strval($workflow_action->id),
                'name' => strval($workflow_action->action_name),
                'to' => $status_to,
                'changes' => $changes,
            ];

            // first action wins when several lead to the same status, the same
            // order the detail screen lists its buttons in
            if ($changes && !isset($result['moves'][$status_to])) {
                $result['moves'][$status_to] = strval($workflow_action->id);
            }
        }

        return $result;
    }


    /**
     * Build every card shown on the board.
     *
     * @param \Illuminate\Support\Collection $records
     * @param array<string, CustomColumn> $value_columns
     * @param array<int, array<string, mixed>> $card_fields
     * @param CustomColumn|null $title_column
     * @param Workflow|null $workflow
     * @param array<int, array<string, mixed>> $statuses
     * @return array<int, array<string, mixed>>
     */
    protected function buildCards($records, array $value_columns, array $card_fields, $title_column, $workflow = null, array $statuses = [])
    {
        // the moves are only ever used by drag and drop and by the drawer
        $with_actions = isset($workflow) && ($this->isEditable() || boolval($this->custom_view->kanban_drawer));

        $cards = [];
        foreach ($records as $record) {
            $values = [];
            $texts = [];
            foreach ($value_columns as $column_name => $custom_column) {
                $values[$column_name] = static::normalizeKey($record->getValue($custom_column));
                $texts[$column_name] = strval($record->getValue($custom_column, true));
            }

            $wf = null;
            if (isset($workflow)) {
                $wf = $this->buildWorkflowCard($record, $statuses, $with_actions);
                $values[static::WORKFLOW_KEY] = $wf['status'];
                $texts[static::WORKFLOW_KEY] = $wf['status_name'];
            }

            $fields = [];
            foreach ($card_fields as $card_field) {
                $item = $card_field['column_item'];
                $html = $item->setCustomValue($record)->html();
                $text = trim(strip_tags(strval($html)));
                if (is_nullorempty($html) && is_nullorempty($text)) {
                    continue;
                }

                $fields[] = [
                    'label' => $card_field['label'],
                    'html' => strval($html),
                    'text' => $text,
                    'name' => $card_field['name'],
                    'value' => isset($card_field['custom_column'])
                        ? array_get($values, $card_field['custom_column']->column_name, '')
                        : '',
                    'pos' => $card_field['pos'],
                    'style' => $card_field['style'],
                    'icon' => static::resolveIcon($card_field['icon'], isset($card_field['custom_column'])
                        ? array_get($values, $card_field['custom_column']->column_name, '') : ''),
                ];
            }

            $cards[] = [
                'id' => $record->id,
                'label' => strval($record->getLabel()),
                'title' => isset($title_column) ? strval($record->getValue($title_column, true)) : '',
                'url' => $record->getUrl(),
                'values' => $values,
                'texts' => $texts,
                'fields' => $fields,
                'wf' => $wf,
            ];
        }

        return $cards;
    }


    /**
     * Card fields, taken from the view columns plus their kanban options.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getCardFields()
    {
        $card_fields = [];
        foreach ($this->custom_view->custom_view_columns_cache as $custom_view_column) {
            $item = $custom_view_column->column_item;
            if (!isset($item)) {
                continue;
            }
            $item = $item->label(array_get($custom_view_column, 'view_column_name'));

            $custom_column = $custom_view_column->custom_column;
            $card_fields[] = [
                'column_item' => $item,
                'custom_column' => (isset($custom_column) && $custom_column->custom_table_id == $this->custom_table->id) ? $custom_column : null,
                'label' => strval($item->label()),
                'name' => isset($custom_column) ? $custom_column->column_name : ('c' . $custom_view_column->id),
                'pos' => $custom_view_column->kanban_position ?: static::POS_META,
                'style' => $custom_view_column->kanban_style ?: static::STYLE_AUTO,
                'icon' => strval($custom_view_column->kanban_icon),
            ];
        }

        return $card_fields;
    }


    /**
     * Columns offered in the filter panel.
     *
     * @param array<int, CustomColumn> $groupables
     * @param CustomColumn|null $assignee_column
     * @param array<int, array<string, mixed>> $card_fields
     * @return array<int, CustomColumn>
     */
    protected function getFilterColumns(array $groupables, $assignee_column, array $card_fields)
    {
        $columns = [];
        foreach ($groupables as $custom_column) {
            $columns[$custom_column->id] = $custom_column;
        }
        if (isset($assignee_column) && static::isSelectableColumn($assignee_column)) {
            $columns[$assignee_column->id] = $assignee_column;
        }
        foreach ($card_fields as $card_field) {
            $custom_column = $card_field['custom_column'];
            if (isset($custom_column) && static::isSelectableColumn($custom_column)) {
                $columns[$custom_column->id] = $custom_column;
            }
        }

        return array_values($columns);
    }


    /**
     * Name / label / options of the given columns, for the browser.
     *
     * @param array<int, CustomColumn> $custom_columns
     * @return array<int, array<string, mixed>>
     */
    protected function buildColumnMeta(array $custom_columns)
    {
        $meta = [];
        foreach ($custom_columns as $custom_column) {
            $meta[] = [
                'name' => $custom_column->column_name,
                'label' => strval($custom_column->column_view_name),
                'options' => $this->buildOptions($custom_column),
            ];
        }

        return $meta;
    }


    /**
     * Selectable values of one column.
     *
     * @param CustomColumn $custom_column
     * @return array<int, array<string, string>>
     */
    protected function buildOptions($custom_column)
    {
        $options = [];
        if (!static::isSelectableColumn($custom_column)) {
            return $options;
        }

        foreach ($custom_column->createSelectOptions() as $key => $label) {
            $options[] = ['key' => strval($key), 'label' => strval($label)];
        }

        return $options;
    }


    /**
     * Fixed color per value, so the same value always looks the same.
     *
     * @param array<string, CustomColumn> $value_columns
     * @return array<string, array<string, string>>
     */
    protected function buildColors(array $value_columns)
    {
        $colors = [];
        foreach ($value_columns as $column_name => $custom_column) {
            if (!static::isSelectableColumn($custom_column)) {
                continue;
            }
            $map = [];
            $index = 0;
            foreach ($custom_column->createSelectOptions() as $key => $label) {
                $map[strval($key)] = static::PALETTE[$index % count(static::PALETTE)];
                $index++;
            }
            $colors[$column_name] = $map;
        }

        return $colors;
    }


    /**
     * WIP limit per board column, kept only for the column the board really
     * uses. The setting stores "{column id}::{value}" / "wf::{status id}", so
     * changing the group column never applies the wrong limits.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @return array<string, int>
     */
    protected function getWipLimits($group_column, $workflow)
    {
        $prefix = $this->getSettingPrefix($group_column, $workflow);

        $limits = [];
        foreach ((array)$this->custom_view->kanban_wips as $row) {
            $key = static::stripSettingPrefix(array_get($row, 'kanban_wip_key'), $prefix);
            $limit = intval(array_get($row, 'kanban_wip_limit'));
            if (is_null($key) || $limit <= 0) {
                continue;
            }
            $limits[$key] = $limit;
        }

        return $limits;
    }


    /**
     * Board columns that mean "finished". A workflow already knows which of its
     * statuses complete the flow, so those are added without any setting.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @param array<int, array<string, mixed>> $statuses
     * @return array<int, string>
     */
    protected function getDoneKeys($group_column, $workflow, array $statuses)
    {
        $keys = [];
        foreach ($statuses as $status) {
            if ($status['done']) {
                $keys[] = $status['key'];
            }
        }

        $prefix = $this->getSettingPrefix($group_column, $workflow);
        foreach ((array)$this->custom_view->kanban_done_keys as $value) {
            $key = static::stripSettingPrefix($value, $prefix);
            if (!is_null($key) && !in_array($key, $keys)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }


    /**
     * Prefix the stored WIP / done values must carry to belong to this board.
     *
     * @param CustomColumn|null $group_column
     * @param mixed $workflow anything not null means "workflow board"
     * @return string
     */
    protected function getSettingPrefix($group_column, $workflow)
    {
        if (isset($workflow)) {
            return static::WORKFLOW_PREFIX . '::';
        }

        return isset($group_column) ? ($group_column->id . '::') : '';
    }


    /**
     * Take the value out of a stored setting, or null when it belongs to
     * another column - a leftover after the group column was changed.
     *
     * @param mixed $stored
     * @param string $prefix
     * @return string|null
     */
    protected static function stripSettingPrefix($stored, string $prefix)
    {
        $stored = strval($stored);
        if ($prefix === '' || $stored === '' || strpos($stored, $prefix) !== 0) {
            return null;
        }

        $key = substr($stored, strlen($prefix));

        return ($key === '') ? null : $key;
    }


    /**
     * Every value a board column can have, for the WIP and "done" settings.
     *
     * The list is built from the table itself - each select column with its own
     * values, plus the statuses of the workflow the table uses - so a setting
     * can only ever hold a value that really exists.
     *
     * @param CustomTable $custom_table
     * @return array<string, string>
     */
    public static function getBoardValueOptions($custom_table)
    {
        $options = [];

        $workflow = Workflow::getWorkflowByTable($custom_table);
        if (isset($workflow)) {
            $label = exmtrans('workflow.status');
            $options[static::WORKFLOW_PREFIX . '::' . Define::WORKFLOW_START_KEYNAME]
                = $label . ' : ' . $workflow->start_status_name;
            foreach ($workflow->workflow_statuses_cache as $workflow_status) {
                $options[static::WORKFLOW_PREFIX . '::' . $workflow_status->id]
                    = $label . ' : ' . $workflow_status->status_name;
            }
        }

        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (!static::isGroupableColumn($custom_column)) {
                continue;
            }
            foreach ($custom_column->createSelectOptions() as $key => $value) {
                $options[$custom_column->id . '::' . $key] = $custom_column->column_view_name . ' : ' . $value;
            }
        }

        return $options;
    }


    /**
     * Where the board columns come from.
     *
     * @return string
     */
    protected function getSource()
    {
        return ($this->custom_view->kanban_source == static::SOURCE_WORKFLOW)
            ? static::SOURCE_WORKFLOW : static::SOURCE_COLUMN;
    }


    /**
     * Column name a quick-add types into. An empty string disables quick add.
     *
     * The card title is what the user reads, so that is what they expect to
     * type. Only when there is no title column does the label take over.
     *
     * @param CustomColumn|null $title_column
     * @return string
     */
    protected function getQuickAddColumnName($title_column)
    {
        if (isset($title_column)) {
            return $title_column->column_name;
        }

        $label_columns = $this->custom_table->getLabelColumns();
        if (isset($label_columns) && !is_string($label_columns)) {
            foreach ($label_columns as $label_column) {
                $custom_column = CustomColumn::getEloquent(array_get($label_column, 'options.table_label_id'));
                if (isset($custom_column)) {
                    return $custom_column->column_name;
                }
            }
        }

        foreach ($this->custom_table->custom_columns_cache as $custom_column) {
            if (array_get($custom_column, 'column_type') == ColumnType::TEXT) {
                return $custom_column->column_name;
            }
        }

        return '';
    }


    /**
     * Get the custom column the board columns are built from.
     *
     * @return CustomColumn|null
     */
    protected function getGroupColumn()
    {
        return $this->getSelectColumn($this->custom_view->kanban_group_column_id);
    }


    /**
     * Get the custom column used for swimlanes. Null means a single lane.
     *
     * @return CustomColumn|null
     */
    protected function getSwimlaneColumn()
    {
        return $this->getSelectColumn($this->custom_view->kanban_swimlane_column_id);
    }


    /**
     * Every column that can drive board columns or swimlanes.
     *
     * @return array<int, CustomColumn>
     */
    protected function getGroupableColumns()
    {
        $columns = [];
        foreach ($this->custom_table->custom_columns_cache as $custom_column) {
            if (static::isGroupableColumn($custom_column)) {
                $columns[] = $custom_column;
            }
        }

        return $columns;
    }


    /**
     * Get a select-type custom column belonging to this table.
     *
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getSelectColumn($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || !static::isGroupableColumn($custom_column)) {
            return null;
        }

        return $custom_column;
    }


    /**
     * How long before the deadline a card starts warning, in hours.
     *
     * A support ticket is late in hours, a delivery date is late in days. The
     * default keeps the ticket behaviour.
     *
     * @return float
     */
    protected function getLimitWarn()
    {
        $hours = $this->custom_view->kanban_limit_warn;
        if (!isset($hours) || !is_numeric($hours) || $hours < 0) {
            return 2;
        }

        return floatval($hours);
    }


    /**
     * The three ages, in days, that turn the card border yellow, orange, red.
     *
     * @return array<int, float>
     */
    protected function getAgeSteps()
    {
        $default = [1, 2, 3];

        $text = $this->custom_view->kanban_age_steps;
        if (is_nullorempty($text)) {
            return $default;
        }

        $steps = [];
        foreach (explode(',', strval($text)) as $step) {
            $step = trim($step);
            if ($step === '' || !is_numeric($step)) {
                continue;
            }
            $steps[] = floatval($step);
        }

        // three ascending numbers or nothing: a half filled setting would paint
        // borders no one can read
        if (count($steps) != 3 || $steps[0] > $steps[1] || $steps[1] > $steps[2]) {
            return $default;
        }

        return $steps;
    }


    /**
     * Get a number-type custom column belonging to this table.
     *
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getNumberColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || !in_array(array_get($custom_column, 'column_type'), static::numberColumnTypes())) {
            return null;
        }

        return $custom_column;
    }


    /**
     * Column types a WIP limit can be counted in.
     *
     * @return array<int, string>
     */
    protected static function numberColumnTypes()
    {
        return [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY];
    }


    /**
     * Get a date-type custom column belonging to this table.
     *
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
     * Get any custom column belonging to this table.
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
     * Max records drawn on the board.
     *
     * @return int
     */
    protected function getMaxCount()
    {
        $max_count = $this->custom_view->kanban_max_count;
        if (!isset($max_count) || !is_numeric($max_count) || $max_count <= 0) {
            $max_count = config('exment.kanban_max_size_count', 300);
        }

        return intval($max_count);
    }


    /**
     * Whether drag and drop may write values.
     *
     * @return bool
     */
    protected function isEditable()
    {
        if (!boolval($this->custom_view->kanban_editable)) {
            return false;
        }

        return $this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
    }


    /**
     * Whether quick add may create records.
     *
     * @return bool
     */
    protected function isCreatable()
    {
        return $this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
    }


    /**
     * Resolve the icon of a card field.
     *
     * A plain class name ("fa-phone") is used for every value. A "value:class"
     * list gives each option its own icon, the way the design does it:
     *   phone:fa-phone,mail:fa-envelope
     *
     * @param string $icon
     * @param string $value
     * @return string
     */
    protected static function resolveIcon($icon, $value)
    {
        $icon = trim(strval($icon));
        if ($icon === '' || strpos($icon, ':') === false) {
            return $icon;
        }

        $fallback = '';
        foreach (explode(',', $icon) as $pair) {
            if (strpos($pair, ':') === false) {
                $fallback = trim($pair);
                continue;
            }
            list($key, $class) = explode(':', $pair, 2);
            if (trim($key) === strval($value)) {
                return trim($class);
            }
        }

        return $fallback;
    }


    /**
     * Convert a raw column value into a board/lane key.
     *
     * @param mixed $value
     * @return string
     */
    protected static function normalizeKey($value)
    {
        if (is_array($value)) {
            $value = count($value) > 0 ? reset($value) : null;
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_null($value) || $value === '') {
            return '';
        }

        return strval($value);
    }


    /**
     * Whether a custom column has a fixed list of values.
     *
     * @param CustomColumn $custom_column
     * @return bool
     */
    protected static function isSelectableColumn($custom_column)
    {
        return in_array(array_get($custom_column, 'column_type'), [ColumnType::SELECT, ColumnType::SELECT_VALTEXT]);
    }


    /**
     * Whether a custom column can drive board columns or swimlanes.
     *
     * @param CustomColumn $custom_column
     * @return bool
     */
    protected static function isGroupableColumn($custom_column)
    {
        if (!static::isSelectableColumn($custom_column)) {
            return false;
        }

        // a multi-value column would put one record in several columns, and a
        // drag could not tell which value to replace
        return !$custom_column->isMultipleEnabled();
    }


    /**
     * Select options for the group / swimlane fields.
     *
     * @param CustomTable $custom_table
     * @return array<int|string, string>
     */
    public static function getKanbanGroupColumnOptions($custom_table)
    {
        $options = [];
        foreach ($custom_table->custom_columns_cache as $custom_column) {
            if (!static::isGroupableColumn($custom_column)) {
                continue;
            }
            $options[$custom_column->id] = $custom_column->column_view_name;
        }

        return $options;
    }


    /**
     * Whether a board can be made for this table at all: either a single-value
     * select column to group by, or a workflow whose statuses become the lanes.
     *
     * @param CustomTable $custom_table
     * @return bool
     */
    public static function canCreateBoard($custom_table)
    {
        if (!is_nullorempty(static::getKanbanGroupColumnOptions($custom_table))) {
            return true;
        }

        return !is_null(Workflow::getWorkflowByTable($custom_table));
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
     * Placement choices of a card field.
     *
     * @return array<string, string>
     */
    public static function getPositionOptions()
    {
        return [
            static::POS_HEADER => exmtrans('custom_view.kanban_position_options.header'),
            static::POS_META => exmtrans('custom_view.kanban_position_options.meta'),
            static::POS_META2 => exmtrans('custom_view.kanban_position_options.meta2'),
            static::POS_FOOT => exmtrans('custom_view.kanban_position_options.foot'),
        ];
    }


    /**
     * Appearance choices of a card field.
     *
     * @return array<string, string>
     */
    public static function getStyleOptions()
    {
        return [
            static::STYLE_AUTO => exmtrans('custom_view.kanban_style_options.auto'),
            static::STYLE_TEXT => exmtrans('custom_view.kanban_style_options.text'),
            static::STYLE_TAG => exmtrans('custom_view.kanban_style_options.tag'),
            static::STYLE_PILL => exmtrans('custom_view.kanban_style_options.pill'),
            static::STYLE_DOT => exmtrans('custom_view.kanban_style_options.dot'),
            static::STYLE_LVL => exmtrans('custom_view.kanban_style_options.lvl'),
            static::STYLE_STATE => exmtrans('custom_view.kanban_style_options.state'),
            static::STYLE_CHIP => exmtrans('custom_view.kanban_style_options.chip'),
            static::STYLE_POINT => exmtrans('custom_view.kanban_style_options.point'),
            static::STYLE_FLAG => exmtrans('custom_view.kanban_style_options.flag'),
            static::STYLE_AVATAR => exmtrans('custom_view.kanban_style_options.avatar'),
            static::STYLE_ICONTEXT => exmtrans('custom_view.kanban_style_options.icontext'),
        ];
    }


    /**
     * Set custom view columns form. For controller.
     *
     * @param string $view_kind_type
     * @param Form $form
     * @param CustomTable $custom_table
     * @param array $options
     * @return void
     */
    // @phpstan-ignore-next-line
    public static function setViewForm($view_kind_type, $form, $custom_table, array $options = [])
    {
        static::setViewInfoboxFields($form);

        $group_options = static::getKanbanGroupColumnOptions($custom_table);
        $value_options = static::getBoardValueOptions($custom_table);
        $date_options = static::getColumnOptionsByType($custom_table, ColumnType::COLUMN_TYPE_DATE());
        $text_options = static::getColumnOptionsByType($custom_table, [
            ColumnType::TEXT, ColumnType::TEXTAREA, ColumnType::AUTO_NUMBER,
        ]);
        $people_options = static::getColumnOptionsByType($custom_table, [
            ColumnType::SELECT, ColumnType::SELECT_VALTEXT, ColumnType::SELECT_TABLE,
            ColumnType::USER, ColumnType::ORGANIZATION,
        ]);
        $has_workflow = !is_null(Workflow::getWorkflowByTable($custom_table));
        // with no column to group by, the workflow is the only board there can be
        $default_source = is_nullorempty($group_options) && $has_workflow
            ? static::SOURCE_WORKFLOW : static::SOURCE_COLUMN;

        // ------------------------------------------------- basic settings --
        // Everything needed for a working board, nothing else.
        $form->exmheader(exmtrans('common.basic_setting'))->hr();

        if ($has_workflow) {
            $form->select('kanban_source', exmtrans("custom_view.kanban_source"))
                ->required()
                ->default($default_source)
                ->disableClear()
                ->options([
                    static::SOURCE_COLUMN => exmtrans("custom_view.kanban_source_options.column"),
                    static::SOURCE_WORKFLOW => exmtrans("custom_view.kanban_source_options.workflow"),
                ])
                ->attribute(['data-filtertrigger' => true])
                ->help(exmtrans("custom_view.help.kanban_source"));
        } else {
            $form->hidden('kanban_source')->default(static::SOURCE_COLUMN);
        }

        // The board cannot be drawn without it, so it is required - but only for
        // a column board. On a workflow board the field is hidden and disabled,
        // which drops it from the request and turns the rule off with it.
        $form->select('kanban_group_column_id', exmtrans("custom_view.kanban_group_column"))
            ->required()
            ->options($group_options)
            ->rules('required_if:kanban_source,' . static::SOURCE_COLUMN, [
                'required_if' => exmtrans('custom_view.message.kanban_group_column_required'),
            ])
            ->attribute(['data-filter' => json_encode(['key' => 'kanban_source', 'value' => static::SOURCE_COLUMN])])
            ->help(exmtrans("custom_view.help.kanban_group_column"));

        $form->select('kanban_title_column_id', exmtrans("custom_view.kanban_title_column"))
            ->options($text_options)
            ->help(exmtrans("custom_view.help.kanban_title_column"));

        $form->switchbool('kanban_editable', exmtrans("custom_view.kanban_editable"))
            ->default(true)
            ->help(exmtrans("custom_view.help.kanban_editable"));

        // -------------------------------------------------- card settings --
        $form->exmheader(exmtrans('custom_view.kanban_card_setting'))->hr();

        // card body columns. Not required: the record label alone is a valid card.
        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.kanban_card_columns"), function ($form) use ($custom_table) {
            $targetOptions = $custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'include_parent' => true,
                'include_workflow' => true,
            ]);

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

            $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
            $form->select('kanban_position', exmtrans("custom_view.kanban_position"))
                ->options(static::getPositionOptions())
                ->default(static::POS_META);
            $form->select('kanban_style', exmtrans("custom_view.kanban_style"))
                ->options(static::getStyleOptions())
                ->default(static::STYLE_AUTO);
            $form->text('kanban_icon', exmtrans("custom_view.kanban_icon"));
            $form->hidden('order')->default(0);
        })->setTableColumnWidth(3, 2, 2, 2, 2, 1)
        ->rowUpDown('order', 10)
        ->descriptionHtml(exmtrans("custom_view.description_custom_view_kanban_columns"));

        // ---------------------------------------------- advanced settings --
        // Board tuning. A simple board never needs to open this part.
        $form->exmheader(exmtrans('custom_view.kanban_advanced_setting'))->hr();

        $form->select('kanban_swimlane_column_id', exmtrans("custom_view.kanban_swimlane_column"))
            ->options($group_options)
            ->help(exmtrans("custom_view.help.kanban_swimlane_column"));

        // values are picked from the table itself, never typed by hand
        $form->hasManyJsonTable('kanban_wips', exmtrans("custom_view.kanban_wips"), function ($form) use ($value_options) {
            $form->select('kanban_wip_key', exmtrans("custom_view.kanban_wip_key"))
                ->required()
                ->options($value_options);
            $form->number('kanban_wip_limit', exmtrans("custom_view.kanban_wip_limit"))
                ->required()
                ->min(1)
                ->max(999)
                ->default(5);
        })->setTableColumnWidth(8, 3, 1)
        ->help(exmtrans("custom_view.help.kanban_wips"));

        $form->select('kanban_wip_column_id', exmtrans("custom_view.kanban_wip_column"))
            ->options(static::getColumnOptionsByType($custom_table, static::numberColumnTypes()))
            ->help(exmtrans("custom_view.help.kanban_wip_column"));

        $form->multipleSelect('kanban_done_keys', exmtrans("custom_view.kanban_done_keys"))
            ->options($value_options)
            ->help(exmtrans("custom_view.help.kanban_done_keys"));

        $form->select('kanban_assignee_column_id', exmtrans("custom_view.kanban_assignee_column"))
            ->options($people_options)
            ->help(exmtrans("custom_view.help.kanban_assignee_column"));

        $form->select('kanban_limit_column_id', exmtrans("custom_view.kanban_limit_column"))
            ->options($date_options)
            ->help(exmtrans("custom_view.help.kanban_limit_column"));

        $form->number('kanban_limit_warn', exmtrans("custom_view.kanban_limit_warn"))
            ->min(0)
            ->max(9999)
            ->default(2)
            ->help(exmtrans("custom_view.help.kanban_limit_warn"));

        $form->select('kanban_age_column_id', exmtrans("custom_view.kanban_age_column"))
            ->options($date_options)
            ->help(exmtrans("custom_view.help.kanban_age_column"));

        $form->text('kanban_age_steps', exmtrans("custom_view.kanban_age_steps"))
            ->default('1,2,3')
            ->help(exmtrans("custom_view.help.kanban_age_steps"));

        $form->select('kanban_ai_column_id', exmtrans("custom_view.kanban_ai_column"))
            ->options($group_options)
            ->help(exmtrans("custom_view.help.kanban_ai_column"));

        $form->switchbool('kanban_kpi', exmtrans("custom_view.kanban_kpi"))
            ->default(true)
            ->help(exmtrans("custom_view.help.kanban_kpi"));

        $form->switchbool('kanban_quickadd', exmtrans("custom_view.kanban_quickadd"))
            ->default(true)
            ->help(exmtrans("custom_view.help.kanban_quickadd"));

        $form->switchbool('kanban_bulk', exmtrans("custom_view.kanban_bulk"))
            ->default(true)
            ->help(exmtrans("custom_view.help.kanban_bulk"));

        $form->switchbool('kanban_drawer', exmtrans("custom_view.kanban_drawer"))
            ->default(true)
            ->help(exmtrans("custom_view.help.kanban_drawer"));

        $form->number('kanban_max_count', exmtrans("custom_view.kanban_max_count"))
            ->min(1)
            ->max(2000)
            ->default(config('exment.kanban_max_size_count', 300))
            ->help(exmtrans("custom_view.help.kanban_max_count"));

        // sort setting
        static::setSortFields($form, $custom_table);

        // filter setting
        static::setFilterFields($form, $custom_table);
    }
}
