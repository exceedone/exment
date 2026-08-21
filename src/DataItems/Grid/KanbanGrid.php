<?php

namespace Exceedone\Exment\DataItems\Grid;

use ExmentAdminCore\Admin\Form;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\ColumnItems\GridCellStyle;
use ExmentAdminCore\Admin\Grid\Exporter;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;

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
     * How the card cover image fills its box.
     */
    public const COVER_FIT_COVER = 'cover';
    public const COVER_FIT_CONTAIN = 'contain';

    /**
     * How the card label strip is drawn.
     */
    public const LABELS_STYLE_CHIP = 'chip';
    public const LABELS_STYLE_BAR = 'bar';

    /**
     * What a full column does to a card dropped on it.
     *
     * A WIP limit nobody has to obey is decoration: the board coloured the
     * column head red and took the card anyway. These let a view make the
     * limit mean something without forcing it on boards that never wanted it.
     */
    public const WIP_ENFORCE_OFF = 'off';
    public const WIP_ENFORCE_WARN = 'warn';
    public const WIP_ENFORCE_BLOCK = 'block';

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
        // An export request lands on the board url. The grid it is handed
        // renders no table: render() runs the exporter, which sends the file
        // and exits - so the board below is never built for those requests.
        $export_grid = $this->getExportGrid();
        if (!is_nullorempty(request(Exporter::$queryName))) {
            return $export_grid;
        }

        // same buttons in the same order as the data grid toolbar
        $tools = [];
        $this->setViewMenuButton($tools, true);
        $this->setTableMenuButton($tools, true);
        $this->setNewButton($tools, true);
        $this->setExportImportButton($tools, $export_grid, true);

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
        $hide_keys = $this->getHideKeys($group_column, $workflow);
        $col_count = $this->getColCount();

        if ($col_count > 0) {
            // One query per board column. A flat "first 300 rows of the table"
            // leaves whole lanes empty when the data is lopsided - and the
            // lane that holds 2,000 finished records would eat the budget of
            // the lanes that hold the work.
            $records = collect();
            foreach ($this->getBoardKeys($group_column, $workflow, $hide_keys) as $key) {
                $records = $records->merge(
                    $this->buildColumnQuery($group_column, $workflow, $hide_keys, $key)->take($col_count)->get()
                );
            }
            $over_limit = false;
        } else {
            $query = $this->newBoardQuery($group_column, $workflow, $hide_keys);
            // take one extra row to detect that the board is truncated
            $records = $query->take($max_count + 1)->get();
            $over_limit = ($records->count() > $max_count);
            if ($over_limit) {
                $records = $records->take($max_count);
            }
        }

        return view('exment::widgets.kanban', [
            'tools' => $tools,
            'error' => null,
            'board' => $this->buildBoard($group_column, $workflow, $records, $hide_keys),
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
    protected function buildBoard($group_column, $workflow, $records, array $hide_keys = [])
    {
        $is_workflow = isset($workflow);
        $group_name = $is_workflow ? static::WORKFLOW_KEY : $group_column->column_name;

        $context = $this->buildCardContext($group_column, $workflow);
        $statuses = $context['statuses'];
        $groupables = $context['groupables'];
        $title_column = $context['title_column'];
        $assignee_column = $context['assignee_column'];
        $limit_column = $context['limit_column'];
        $age_column = $context['age_column'];
        $ai_column = $context['ai_column'];
        $wip_column = $context['wip_column'];
        $cover_column = $context['cover_column'];
        $labels_column = $context['labels_column'];
        $badge_column = $context['badge_column'];
        $sum_column = $context['sum_column'];
        $progress_column = $context['progress_column'];
        $card_fields = $context['card_fields'];
        $value_columns = $context['value_columns'];

        $swimlane_column = $this->getSwimlaneColumn();
        $col_count = $this->getColCount();
        $col_stats = $col_count > 0
            ? $this->buildColumnStats($group_column, $workflow, $hide_keys, $context, $swimlane_column)
            : ['flat' => [], 'lanes' => []];

        $cards = $this->buildCards($records, $value_columns, $card_fields, $title_column, $workflow, $statuses, $cover_column, $labels_column);

        // the workflow status behaves like one more groupable/filterable column
        $groupable_meta = $this->buildColumnMeta($groupables);
        $filter_meta = $this->buildColumnMeta($this->getFilterColumns($groupables, $assignee_column, $card_fields, $labels_column));
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

            // trello-style card extras: photo cover, colored label strip and a
            // small corner badge
            'cover_column' => isset($cover_column) ? $cover_column->column_name : '',
            'cover_fit' => $this->getCoverFit(),
            'labels_column' => isset($labels_column) ? $labels_column->column_name : '',
            'labels_style' => $this->getLabelsStyle(),
            'badge_column' => isset($badge_column) ? $badge_column->column_name : '',
            'badge_label' => isset($badge_column) ? strval($badge_column->column_view_name) : '',

            // a lane is not always measured in cards, and "close to the
            // deadline" / "sitting too long" mean different things per field
            'wip_column' => isset($wip_column) ? $wip_column->column_name : '',
            'wip_format' => $this->buildNumberFormat($wip_column),
            'limit_warn' => $this->getLimitWarn(),
            'age_steps' => $this->getAgeSteps(),

            // the column head can carry a running total of one number column -
            // "how much money is waiting here" - separately from the WIP limit
            'sum_column' => isset($sum_column) ? $sum_column->column_name : '',
            'sum_label' => isset($sum_column) ? strval($sum_column->column_view_name) : '',
            'sum_format' => $this->buildNumberFormat($sum_column),
            'col_age' => boolval($this->custom_view->kanban_col_age),

            'progress_column' => isset($progress_column) ? $progress_column->column_name : '',
            'progress_label' => isset($progress_column) ? strval($progress_column->column_view_name) : '',
            'progress_max' => $this->getProgressMax(),

            // board columns the view does not want to look at. The records
            // behind them are never queried, the browser only needs the keys
            // to leave the columns out.
            'hide_keys' => $hide_keys,

            // Cards the board has to shout about. Unlike done and hide keys
            // these are not read against the board columns, so each key
            // carries the column it belongs to: a card can be blocked by one
            // column while the board is grouped by another.
            'blocked' => $this->getValueKeyList('kanban_blocked_keys'),
            'expedite' => $this->getValueKeyList('kanban_expedite_keys'),

            // "load per column" mode: the counts come from the database, not
            // from the cards that happen to be on the page
            'col_count' => $col_count,
            'col_stats' => (object) $col_stats['flat'],
            // the same figures cut by the swimlane the view was set up with,
            // so a split board can label each cell instead of hiding the lot
            'col_stats_lane' => (object) $col_stats['lanes'],
            // paging, keyword search and the figures all read the saved view by
            // suuid, so a preview of unsaved settings has no url to ask
            'more_url' => $this->preview
                ? '' : admin_url('data', [$this->custom_table->table_name, 'kanbanCards']),
            'view_suuid' => strval($this->custom_view->suuid),

            'groupables' => $groupable_meta,
            'filters' => $filter_meta,
            'assignees' => isset($assignee_column) ? $this->buildOptions($assignee_column) : [],
            // assignee values that stand for whoever is looking at the board
            'me' => $this->getMyKeys($assignee_column),
            'colors' => $colors,

            'wip' => $this->getWipLimits($group_column, $workflow),
            'wip_enforce' => $this->getWipEnforce(),
            // the rule a column head shows: what has to be true before a card
            // may leave, written where the work happens instead of in a wiki
            'policies' => (object) $this->getPolicies($group_column, $workflow),
            'done_keys' => $this->getDoneKeys($group_column, $workflow, $statuses),
            'features' => [
                'kpi' => boolval($this->custom_view->kanban_kpi),
                'quickadd' => boolval($this->custom_view->kanban_quickadd) && $this->isCreatable(),
                // one record at a time is the only safe way through a workflow,
                // so a bulk status change is offered for plain columns only
                'bulk' => boolval($this->custom_view->kanban_bulk) && $this->isEditable(),
                'bulk_move' => boolval($this->custom_view->kanban_bulk) && $this->isEditable() && !$is_workflow,
                'drawer' => boolval($this->custom_view->kanban_drawer),
                // The drawer is the only place the history is shown, and only
                // a workflow keeps one.
                //
                // The switch was added after these views were saved, so it is
                // missing from most of them - and the form shows a missing
                // switch as on (its default). Reading it as off here made the
                // setting screen say one thing and the board do another, until
                // the view happened to be saved again.
                'history' => $is_workflow && boolval($this->custom_view->kanban_drawer)
                    && boolval($this->custom_view->kanban_history ?? true),
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
     * @param CustomColumn|null $cover_column image shown as the card cover
     * @param CustomColumn|null $labels_column values shown as the label strip
     * @return array<int, array<string, mixed>>
     */
    protected function buildCards($records, array $value_columns, array $card_fields, $title_column, $workflow = null, array $statuses = [], $cover_column = null, $labels_column = null)
    {
        // the moves are only ever used by drag and drop and by the drawer
        $with_actions = !$this->preview && isset($workflow)
            && ($this->isEditable() || boolval($this->custom_view->kanban_drawer));

        // one option list shared by every card, not one lookup per card
        $labels_options = [];
        if (isset($labels_column)) {
            foreach ($labels_column->createSelectOptions() as $key => $label) {
                $labels_options[strval($key)] = strval($label);
            }
        }

        // CustomValue::getValue() builds a fresh column item on every call, and
        // a card reads every board column twice - raw for the browser to group
        // and filter on, formatted for it to search and show. One item per
        // column, moved from record to record, returns the same values for a
        // fraction of the work. Built here rather than taken from
        // CustomColumn::$column_item: that instance is shared for the whole
        // request, and the options set below would follow it everywhere.
        $items = [];
        foreach ($value_columns as $column_name => $custom_column) {
            $item = CustomItem::getItem($custom_column);
            if (isset($item)) {
                $items[$column_name] = $item->options(['format' => null, 'disable_currency_symbol' => false]);
            }
        }

        $cards = [];
        foreach ($records as $record) {
            $values = [];
            $texts = [];
            foreach ($value_columns as $column_name => $custom_column) {
                $item = array_get($items, $column_name);
                if (!isset($item)) {
                    $values[$column_name] = static::normalizeKey(null);
                    $texts[$column_name] = '';
                    continue;
                }
                $item->setCustomValue($record);
                $values[$column_name] = static::normalizeKey($item->value());
                $texts[$column_name] = strval($item->text());
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

            // when this record arrived in the column it now sits in: the last
            // workflow step on a workflow board, the last edit otherwise. The
            // board averages it per column to show where work piles up.
            $entered = null;
            if (isset($workflow)) {
                $workflow_value = $record->workflow_value;
                $entered = isset($workflow_value)
                    ? ($workflow_value->updated_at ?: $workflow_value->created_at)
                    : $record->created_at;
            } else {
                $entered = $record->updated_at ?: $record->created_at;
            }

            $cards[] = [
                'id' => $record->id,
                'label' => strval($record->getLabel()),
                'entered' => isset($entered) ? \Carbon\Carbon::parse($entered)->format('Y-m-d H:i:s') : '',
                // the title column is one of the value columns, so its text
                // has already been read once for this record
                'title' => isset($title_column)
                    ? strval(array_get($texts, $title_column->column_name, '')) : '',
                'url' => $record->getUrl(),
                'values' => $values,
                'texts' => $texts,
                'fields' => $fields,
                'cover' => isset($cover_column) ? $this->buildCoverUrl($record, $cover_column) : '',
                'labels' => isset($labels_column)
                    ? $this->buildCardLabels($record, $labels_column, $labels_options,
                        array_get($items, $labels_column->column_name) ? $items[$labels_column->column_name]->value() : null)
                    : [],
                'wf' => $wf,
            ];
        }

        return $cards;
    }


    /**
     * Public url of the card cover image. Empty when the record has none.
     * A multi-image column shows its first image, the same way the grid does.
     *
     * @param CustomValue $record
     * @param CustomColumn $cover_column
     * @return string
     */
    protected function buildCoverUrl($record, $cover_column)
    {
        $path = $record->getValue($cover_column);
        if (is_array($path)) {
            $path = count($path) > 0 ? reset($path) : null;
        }
        if (is_nullorempty($path) || !is_string($path)) {
            return '';
        }

        return strval(ExmentFile::getUrl($path) ?? '');
    }


    /**
     * Every label of one record: key for the color map, label for the eye.
     * Unlike a board key a card may carry several labels, so nothing is
     * flattened here - this is what makes a multi-value column usable.
     *
     * @param CustomValue $record
     * @param CustomColumn $labels_column
     * @param array<string, string> $labels_options
     * @return array<int, array<string, string>>
     */
    protected function buildCardLabels($record, $labels_column, array $labels_options, $raw = null)
    {
        if (!isset($raw)) {
            $raw = $record->getValue($labels_column);
        }

        $labels = [];
        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            $key = static::normalizeKey($value);
            if ($key === '') {
                continue;
            }
            $labels[] = ['key' => $key, 'label' => array_get($labels_options, $key, $key)];
        }

        return $labels;
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
     * @param CustomColumn|null $labels_column
     * @return array<int, CustomColumn>
     */
    protected function getFilterColumns(array $groupables, $assignee_column, array $card_fields, $labels_column = null)
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
        if (isset($labels_column)) {
            $columns[$labels_column->id] = $labels_column;
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
            // the data list already lets a column say what each value looks
            // like. Reuse those colors so one value never wears two of them,
            // and fall back to the palette for the values left unset.
            $picked = GridCellStyle::parseValueColors($custom_column->getOption('grid_value_colors'));

            $map = [];
            $index = 0;
            foreach ($custom_column->createSelectOptions() as $key => $label) {
                $color = array_get($picked, strval($key) . '.color');
                $map[strval($key)] = $color ?: static::PALETTE[$index % count(static::PALETTE)];
                $index++;
            }
            $colors[$column_name] = $map;
        }

        return $colors;
    }


    /**
     * The export follows the board.
     *
     * A hidden column is not part of what this view shows, so its records are
     * not part of what it exports either - otherwise the file would disagree
     * with the screen it came from.
     *
     * @return \ExmentAdminCore\Admin\Grid
     */
    protected function getExportGrid()
    {
        $grid = parent::getExportGrid();

        $group_column = null;
        $workflow = null;
        if ($this->getSource() == static::SOURCE_WORKFLOW) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
        } else {
            $group_column = $this->getGroupColumn();
        }
        if (!isset($workflow) && !isset($group_column)) {
            return $grid;
        }

        $hide_keys = $this->getHideKeys($group_column, $workflow);
        if (!empty($hide_keys)) {
            $this->applyBoardKeys($grid->model(), $group_column, $workflow, $hide_keys, true);
        }

        return $grid;
    }


    /**
     * Every column of a card the browser needs, resolved once.
     *
     * The load-more endpoint builds cards for an already drawn board, so it
     * needs exactly the same set - keeping it in one place is what stops the
     * two paths from drifting apart.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @return array<string, mixed>
     */
    protected function buildCardContext($group_column, $workflow)
    {
        $context = [
            'statuses' => isset($workflow) ? $this->buildWorkflowStatuses($workflow) : [],
            'groupables' => $this->getGroupableColumns(),
            'title_column' => $this->getColumnById($this->custom_view->kanban_title_column_id),
            'assignee_column' => $this->getColumnById($this->custom_view->kanban_assignee_column_id),
            'limit_column' => $this->getDateColumnById($this->custom_view->kanban_limit_column_id),
            'age_column' => $this->getDateColumnById($this->custom_view->kanban_age_column_id),
            'ai_column' => $this->getSelectColumn($this->custom_view->kanban_ai_column_id),
            'wip_column' => $this->getNumberColumnById($this->custom_view->kanban_wip_column_id),
            'cover_column' => $this->getImageColumnById($this->custom_view->kanban_cover_column_id),
            'labels_column' => $this->getSelectableColumnById($this->custom_view->kanban_labels_column_id),
            'badge_column' => $this->getBadgeColumnById($this->custom_view->kanban_badge_column_id),
            'sum_column' => $this->getNumberColumnById($this->custom_view->kanban_sum_column_id),
            'progress_column' => $this->getNumberColumnById($this->custom_view->kanban_progress_column_id),
            'card_fields' => $this->getCardFields(),
        ];

        // every column whose raw value the browser needs
        $value_columns = [];
        foreach ($context['groupables'] as $custom_column) {
            $value_columns[$custom_column->column_name] = $custom_column;
        }
        foreach (['title_column', 'assignee_column', 'limit_column', 'age_column', 'ai_column', 'wip_column',
            'labels_column', 'badge_column', 'sum_column', 'progress_column', ] as $name) {
            $custom_column = $context[$name];
            if (isset($custom_column)) {
                $value_columns[$custom_column->column_name] = $custom_column;
            }
        }
        foreach ($context['card_fields'] as $card_field) {
            if (isset($card_field['custom_column'])) {
                $value_columns[$card_field['custom_column']->column_name] = $card_field['custom_column'];
            }
        }
        $context['value_columns'] = $value_columns;

        return $context;
    }


    /**
     * The board query: the view's own filter and sort, minus the columns the
     * view hides.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @param array<int, string> $hide_keys
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function newBoardQuery($group_column, $workflow, array $hide_keys)
    {
        $query = $this->custom_table->getValueQuery();
        // each board column is its own query, and so is the export grid: the
        // search service has to start fresh or it leaves out a join it thinks
        // it already made
        $this->custom_view->resetSearchService();
        $this->custom_view->filterSortModel($query);
        if (isset($workflow)) {
            // without this every card would run its own query for the status
            $query->with(['workflow_value', 'workflow_value.workflow_status']);
        }
        if (!empty($hide_keys)) {
            $this->applyBoardKeys($query, $group_column, $workflow, $hide_keys, true);
        }

        return $query;
    }


    /**
     * The board query narrowed to one board column.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @param array<int, string> $hide_keys
     * @param string $key
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildColumnQuery($group_column, $workflow, array $hide_keys, $key)
    {
        $query = $this->newBoardQuery($group_column, $workflow, $hide_keys);
        $this->applyBoardKeys($query, $group_column, $workflow, [$key], false);

        return $query;
    }


    /**
     * Narrow a query to one swimlane.
     *
     * The board calls a record with no value there the empty lane, and so does
     * the aggregate that counted it, so null and "" have to answer to the same
     * key here as well.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param CustomColumn $lane_column
     * @param string $lane
     * @return void
     */
    protected function applyLaneKey($query, $lane_column, string $lane)
    {
        $grammar = \DB::connection()->getQueryGrammar();
        $expr = $grammar->wrap($lane_column->getQueryKey());

        if ($lane === static::EMPTY_KEY) {
            $query->whereRaw('(' . $expr . ' is null or ' . $expr . " = '')");
            return;
        }

        $query->whereRaw($expr . ' = ?', [$lane]);
    }


    /**
     * Narrow a query to - or away from - a set of board columns.
     *
     * A board column is not always a stored value: the workflow start status
     * is "no workflow row yet", and the empty column is "anything that is not
     * one of the choices". Both have to be said in sql here, because this is
     * what decides which records are read at all.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @param array<int, string> $keys
     * @param bool $exclude
     * @return void
     */
    protected function applyBoardKeys($query, $group_column, $workflow, array $keys, bool $exclude)
    {
        $db_table = getDBTableName($this->custom_table);
        $keys = array_values(array_unique(array_map('strval', $keys)));
        if (empty($keys)) {
            return;
        }

        if (isset($workflow)) {
            $table_name = $this->custom_table->table_name;
            $has_start = in_array(Define::WORKFLOW_START_KEYNAME, $keys, true);
            $status_keys = array_values(array_diff($keys, [Define::WORKFLOW_START_KEYNAME]));

            $latest = function ($q) use ($table_name) {
                $q->select('morph_id')->from('workflow_values')
                    ->where('morph_type', $table_name)->where('latest_flg', 1);
            };
            $inStatus = function ($q) use ($table_name, $status_keys) {
                $q->select('morph_id')->from('workflow_values')
                    ->where('morph_type', $table_name)->where('latest_flg', 1)
                    ->whereIn('workflow_status_to_id', $status_keys);
            };

            if ($exclude) {
                if ($has_start) {
                    $query->whereIn($db_table . '.id', $latest);
                }
                if (!empty($status_keys)) {
                    $query->whereNotIn($db_table . '.id', $inStatus);
                }

                return;
            }

            $query->where(function ($q) use ($has_start, $status_keys, $db_table, $latest, $inStatus) {
                if ($has_start) {
                    $q->whereNotIn($db_table . '.id', $latest);
                }
                if (!empty($status_keys)) {
                    $q->orWhereIn($db_table . '.id', $inStatus);
                }
            });

            return;
        }

        $query_key = $group_column->getQueryKey();
        $real_keys = $this->getRealBoardKeys($group_column);
        $has_empty = in_array(static::EMPTY_KEY, $keys, true);
        $value_keys = array_values(array_diff($keys, [static::EMPTY_KEY]));

        if ($exclude) {
            if (!empty($value_keys)) {
                // "not in" is never true for a null, so the null case has to be
                // spelled out or every unset record would vanish
                $query->where(function ($q) use ($query_key, $value_keys) {
                    $q->whereNotIn($query_key, $value_keys)->orWhereNull($query_key);
                });
            }
            if ($has_empty) {
                $query->whereNotNull($query_key)->whereIn($query_key, $real_keys);
            }

            return;
        }

        $query->where(function ($q) use ($query_key, $value_keys, $has_empty, $real_keys) {
            if (!empty($value_keys)) {
                $q->whereIn($query_key, $value_keys);
            }
            if ($has_empty) {
                $q->orWhereNull($query_key)->orWhereNotIn($query_key, $real_keys);
            }
        });
    }


    /**
     * Board column keys, in board order, minus the hidden ones.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @param array<int, string> $hide_keys
     * @return array<int, string>
     */
    protected function getBoardKeys($group_column, $workflow, array $hide_keys = [])
    {
        $keys = [];
        if (isset($workflow)) {
            foreach ($this->buildWorkflowStatuses($workflow) as $status) {
                $keys[] = $status['key'];
            }
        } else {
            $keys = $this->getRealBoardKeys($group_column);
            $keys[] = static::EMPTY_KEY;
        }

        return array_values(array_diff($keys, $hide_keys));
    }


    /**
     * Every value the group column offers, as strings.
     *
     * @param CustomColumn|null $group_column
     * @return array<int, string>
     */
    protected function getRealBoardKeys($group_column)
    {
        $keys = [];
        if (!isset($group_column)) {
            return $keys;
        }
        foreach ($group_column->createSelectOptions() as $key => $label) {
            $keys[] = strval($key);
        }

        return $keys;
    }


    /**
     * Count, total and average age of every board column, straight from the
     * database.
     *
     * The board only ever holds a slice of each column, so counting the cards
     * on the page would understate every figure. These are the real ones.
     *
     * With a swimlane column the same pass counts per (lane, column) instead,
     * and the column figures are the lanes added back together - the table is
     * still read once, only the number of result rows grows.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @param array<int, string> $hide_keys
     * @param array<string, mixed> $context columns resolved by buildCardContext
     * @param CustomColumn|null $lane_column
     * @return array<string, mixed> keys 'flat' and 'lanes'
     */
    protected function buildColumnStats($group_column, $workflow, array $hide_keys, array $context, $lane_column = null)
    {
        $sum_column = $context['sum_column'];
        $wip_column = $context['wip_column'];
        $assignee_column = $context['assignee_column'];
        $limit_column = $context['limit_column'];
        $age_column = $context['age_column'];

        $db_table = getDBTableName($this->custom_table);
        $query = $this->custom_table->getValueQuery();
        $this->custom_view->resetSearchService();
        $this->custom_view->filterSortModel($query, ['sort' => false]);
        if (!empty($hide_keys)) {
            $this->applyBoardKeys($query, $group_column, $workflow, $hide_keys, true);
        }

        $base = $query->toBase();
        // an aggregate carries neither the view's ordering nor its paging
        $base->orders = null;
        $base->limit = null;
        $base->offset = null;

        $grammar = \DB::connection()->getQueryGrammar();
        $table_expr = $grammar->wrapTable($db_table);

        if (isset($workflow)) {
            $table_name = $this->custom_table->table_name;
            $base->leftJoin('workflow_values as kb_wf', function ($join) use ($db_table, $table_name) {
                $join->on('kb_wf.morph_id', '=', $db_table . '.id')
                    ->where('kb_wf.morph_type', $table_name)
                    ->where('kb_wf.latest_flg', 1);
            });
            $group_expr = 'kb_wf.workflow_status_to_id';
            $entered_expr = 'coalesce(kb_wf.updated_at, ' . $table_expr . '.created_at)';
        } else {
            $group_expr = $grammar->wrap($group_column->getQueryKey());
            $entered_expr = 'coalesce(' . $table_expr . '.updated_at, ' . $table_expr . '.created_at)';
        }

        $selects = [
            \DB::raw($group_expr . ' as kb_key'),
            \DB::raw('count(*) as kb_count'),
            \DB::raw('sum(timestampdiff(second, ' . $entered_expr . ', now())) as kb_seconds'),
        ];
        if (isset($sum_column)) {
            $selects[] = \DB::raw('sum(' . $grammar->wrap($sum_column->getQueryKey()) . ') as kb_sum');
        }
        if (isset($wip_column)) {
            $selects[] = \DB::raw('sum(' . $grammar->wrap($wip_column->getQueryKey()) . ') as kb_load');
        }
        // the KPI row is counted here as well: it sits right above these column
        // heads, so it has to be reading the same table, not the page
        if (isset($assignee_column)) {
            $who = $grammar->wrap($assignee_column->getQueryKey());
            $selects[] = \DB::raw("sum(case when $who is null or $who = '' then 1 else 0 end) as kb_unassigned");
        }
        if (isset($limit_column)) {
            $due = $grammar->wrap($limit_column->getQueryKey());
            $selects[] = \DB::raw("sum(case when $due is not null and $due <> '' and $due < now() then 1 else 0 end) as kb_breach");
        }
        if (isset($age_column)) {
            $since = $grammar->wrap($age_column->getQueryKey());
            $selects[] = \DB::raw("sum(case when $since is null or $since = '' then 0 else timestampdiff(second, $since, now()) end) as kb_agesum");
            $selects[] = \DB::raw("sum(case when $since is null or $since = '' then 0 else 1 end) as kb_agen");
        }

        // one more grouping level: the swimlane the view was set up with
        $lane_expr = isset($lane_column) ? $grammar->wrap($lane_column->getQueryKey()) : null;
        if (isset($lane_expr)) {
            $selects[] = \DB::raw($lane_expr . ' as kb_lane');
        }

        $groups = [\DB::raw($group_expr)];
        if (isset($lane_expr)) {
            $groups[] = \DB::raw($lane_expr);
        }

        $real_keys = $this->getRealBoardKeys($group_column);
        $stats = [];
        foreach ($base->select($selects)->groupBy($groups)->get() as $row) {
            $raw = $row->kb_key;
            $key = (is_null($raw) || $raw === '') ? null : strval($raw);
            if (isset($workflow)) {
                $key = is_null($key) ? Define::WORKFLOW_START_KEYNAME : $key;
            } elseif (is_null($key) || !in_array($key, $real_keys, true)) {
                // anything that is not one of the choices lands in the same
                // "not set" column the browser draws
                $key = static::EMPTY_KEY;
            }

            // the browser calls an empty lane by the same name, so the two
            // sides line up without a translation table
            $lane = static::EMPTY_KEY;
            if (isset($lane_column)) {
                $lane_raw = $row->kb_lane;
                $lane = (is_null($lane_raw) || $lane_raw === '') ? static::EMPTY_KEY : strval($lane_raw);
            }

            if (!isset($stats[$lane][$key])) {
                $stats[$lane][$key] = ['total' => 0, 'sum' => null, 'load' => null, 'seconds' => 0,
                    'unassigned' => 0, 'breach' => 0, 'agesum' => 0, 'agen' => 0, ];
            }
            $stats[$lane][$key]['total'] += intval($row->kb_count);
            $stats[$lane][$key]['seconds'] += floatval($row->kb_seconds);
            if (isset($sum_column)) {
                $stats[$lane][$key]['sum'] = floatval($stats[$lane][$key]['sum']) + floatval($row->kb_sum);
            }
            if (isset($wip_column)) {
                $stats[$lane][$key]['load'] = floatval($stats[$lane][$key]['load']) + floatval($row->kb_load);
            }
            if (isset($assignee_column)) {
                $stats[$lane][$key]['unassigned'] += intval($row->kb_unassigned);
            }
            if (isset($limit_column)) {
                $stats[$lane][$key]['breach'] += intval($row->kb_breach);
            }
            if (isset($age_column)) {
                $stats[$lane][$key]['agesum'] += floatval($row->kb_agesum);
                $stats[$lane][$key]['agen'] += intval($row->kb_agen);
            }
        }

        // The column head still wants the whole column, and that is the lanes
        // added back together - every figure here is a sum. The average age is
        // not, which is why the raw seconds are carried this far and divided
        // only in finishColumnStats().
        $flat = [];
        foreach ($stats as $per_key) {
            foreach ($per_key as $key => $stat) {
                if (!isset($flat[$key])) {
                    $flat[$key] = $stat;
                    continue;
                }
                foreach ($stat as $name => $value) {
                    if (is_null($value)) {
                        continue;
                    }
                    $flat[$key][$name] = floatval($flat[$key][$name]) + $value;
                }
            }
        }

        $lanes = [];
        foreach ($stats as $lane => $per_key) {
            $lanes[$lane] = $this->finishColumnStats($per_key, $wip_column);
        }

        return [
            'flat' => $this->finishColumnStats($flat, $wip_column),
            'lanes' => isset($lane_column) ? $lanes : [],
        ];
    }


    /**
     * Turn the sums collected for a set of board columns into the figures the
     * board draws: the age becomes an average, and the WIP load falls back to
     * the card count when no number column drives it.
     *
     * @param array<string, array<string, mixed>> $stats
     * @param CustomColumn|null $wip_column
     * @return array<string, array<string, mixed>>
     */
    protected function finishColumnStats(array $stats, $wip_column)
    {
        $result = [];
        foreach ($stats as $key => $stat) {
            $total = intval($stat['total']);
            $result[$key] = [
                'total' => $total,
                'sum' => $stat['sum'],
                // the WIP metric is the card count unless a number column took
                // its place
                'load' => isset($wip_column) ? $stat['load'] : $total,
                'age' => $total > 0 ? round($stat['seconds'] / $total / 86400, 1) : null,
                'unassigned' => intval($stat['unassigned']),
                'breach' => intval($stat['breach']),
                'agesum' => $stat['agesum'],
                'agen' => intval($stat['agen']),
            ];
        }

        return $result;
    }


    /**
     * Cards matching a keyword, looked for in the whole table.
     *
     * The board is loaded a slice at a time, so the browser can only filter
     * what already arrived - a match sitting at row 500 of a column would
     * never show up. This goes and gets it. Each column is searched on its
     * own, otherwise one busy column would fill the whole answer.
     *
     * @param string $keyword
     * @return array<string, mixed>
     */
    public function searchCards(string $keyword)
    {
        $empty = ['cards' => [], 'has_more' => false];
        $keyword = trim($keyword);
        if ($keyword === '') {
            return $empty;
        }

        $group_column = null;
        $workflow = null;
        if ($this->getSource() == static::SOURCE_WORKFLOW) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
        } else {
            $group_column = $this->getGroupColumn();
        }
        if (!isset($workflow) && !isset($group_column)) {
            return $empty;
        }

        $col_count = $this->getColCount();
        if ($col_count <= 0) {
            return $empty;
        }

        $hide_keys = $this->getHideKeys($group_column, $workflow);
        $context = $this->buildCardContext($group_column, $workflow);

        $records = collect();
        $has_more = false;
        foreach ($this->getBoardKeys($group_column, $workflow, $hide_keys) as $key) {
            $query = $this->buildColumnQuery($group_column, $workflow, $hide_keys, $key);
            $this->applyKeyword($query, $keyword, $context['value_columns']);
            $rows = $query->take($col_count + 1)->get();
            if ($rows->count() > $col_count) {
                $has_more = true;
                $rows = $rows->take($col_count);
            }
            $records = $records->merge($rows);
        }

        return [
            'cards' => $this->buildCards(
                $records,
                $context['value_columns'],
                $context['card_fields'],
                $context['title_column'],
                $workflow,
                $context['statuses'],
                $context['cover_column'],
                $context['labels_column']
            ),
            'has_more' => $has_more,
        ];
    }


    /**
     * Narrow a query to the records a keyword could match.
     *
     * Deliberately wide: the browser filters the answer again with the rules
     * it already had, so anything extra here is dropped there. Missing a row
     * would be the real fault, sending a few too many is not.
     *
     * A choice column stores its key and shows its label, so the label is what
     * the user types - those are turned back into keys. A column that points
     * at another table (user, organisation, relation) stores an id, and its
     * label lives elsewhere; the browser still finds those among the cards it
     * holds, but this cannot go looking for them.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $keyword
     * @param array<string, CustomColumn> $value_columns
     * @return void
     */
    protected function applyKeyword($query, string $keyword, array $value_columns)
    {
        $grammar = \DB::connection()->getQueryGrammar();
        // the wildcards belong to us, not to whatever the user typed
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword) . '%';

        $applied = 0;
        $query->where(function ($q) use ($value_columns, $keyword, $like, $grammar, &$applied) {
            foreach ($value_columns as $custom_column) {
                $expr = \DB::raw($grammar->wrap($custom_column->getQueryKey()));

                if (static::isSelectableColumn($custom_column)) {
                    $keys = [];
                    foreach ($custom_column->createSelectOptions() as $option_key => $label) {
                        if (mb_stripos(strval($label), $keyword) !== false) {
                            $keys[] = strval($option_key);
                        }
                    }
                    if (!empty($keys)) {
                        $q->orWhereIn($expr, $keys);
                        $applied++;
                    }
                    continue;
                }

                $q->orWhere($expr, 'LIKE', $like);
                $applied++;
            }
        });

        // an empty where(...) would quietly match everything
        if ($applied === 0) {
            $query->whereRaw('1 = 0');
        }
    }


    /**
     * The column figures on their own, for a board that has just been written to.
     *
     * The board carries them in the page, so a card the user drags leaves them
     * behind. Reading them again costs a fraction of a board build and is the
     * only way to keep the breach count and the average age right - neither can
     * be adjusted card by card in the browser.
     *
     * @return array<string, mixed>
     */
    public function columnStats()
    {
        $empty = ['flat' => (object) [], 'lanes' => (object) []];

        $group_column = null;
        $workflow = null;
        if ($this->getSource() == static::SOURCE_WORKFLOW) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
        } else {
            $group_column = $this->getGroupColumn();
        }
        if (!isset($workflow) && !isset($group_column)) {
            return $empty;
        }
        if ($this->getColCount() <= 0) {
            return $empty;
        }

        $hide_keys = $this->getHideKeys($group_column, $workflow);
        $context = $this->buildCardContext($group_column, $workflow);
        $stats = $this->buildColumnStats($group_column, $workflow, $hide_keys, $context, $this->getSwimlaneColumn());

        return [
            'flat' => (object) $stats['flat'],
            'lanes' => (object) $stats['lanes'],
        ];
    }

    /**
     * The next slice of one board column, for the "load more" button.
     *
     * A split board asks for one cell instead: the button then sits in the cell
     * whose records it brings back, rather than handing another lane's records
     * to whoever clicked. Only the swimlane the view was set up with is
     * accepted - it is the one the figures beside the button were measured by.
     *
     * @param string $key
     * @param int $offset
     * @param string|null $lane
     * @return array<string, mixed>
     */
    public function moreCards($key, int $offset, $lane = null)
    {
        $empty = ['cards' => [], 'has_more' => false];

        $group_column = null;
        $workflow = null;
        if ($this->getSource() == static::SOURCE_WORKFLOW) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
        } else {
            $group_column = $this->getGroupColumn();
        }
        if (!isset($workflow) && !isset($group_column)) {
            return $empty;
        }

        $hide_keys = $this->getHideKeys($group_column, $workflow);
        if (in_array(strval($key), $hide_keys, true)) {
            return $empty;
        }
        if (!in_array(strval($key), $this->getBoardKeys($group_column, $workflow, $hide_keys), true)) {
            return $empty;
        }

        $col_count = $this->getColCount();
        if ($col_count <= 0) {
            return $empty;
        }

        $query = $this->buildColumnQuery($group_column, $workflow, $hide_keys, $key);

        $lane_column = $this->getSwimlaneColumn();
        if (isset($lane_column) && !is_null($lane) && strval($lane) !== '') {
            $this->applyLaneKey($query, $lane_column, strval($lane));
        }

        // one row over the page, the same trick the board itself uses to know
        // whether there is anything left
        $records = $query->skip(max(0, $offset))->take($col_count + 1)->get();
        $has_more = ($records->count() > $col_count);
        if ($has_more) {
            $records = $records->take($col_count);
        }

        $context = $this->buildCardContext($group_column, $workflow);

        return [
            'cards' => $this->buildCards(
                $records,
                $context['value_columns'],
                $context['card_fields'],
                $context['title_column'],
                $workflow,
                $context['statuses'],
                $context['cover_column'],
                $context['labels_column']
            ),
            'has_more' => $has_more,
        ];
    }


    /**
     * Board columns the view leaves out.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @return array<int, string>
     */
    protected function getHideKeys($group_column, $workflow)
    {
        $prefix = $this->getSettingPrefix($group_column, $workflow);

        $keys = [];
        foreach ((array)$this->custom_view->kanban_hide_keys as $value) {
            $key = static::stripSettingPrefix($value, $prefix);
            if (!is_null($key) && !in_array($key, $keys)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }


    /**
     * How many cards one board column loads at a time. 0 keeps the old
     * behaviour: one flat query for the whole board.
     *
     * @return int
     */
    protected function getColCount()
    {
        $count = $this->custom_view->kanban_col_count;
        if (!isset($count) || !is_numeric($count) || $count <= 0) {
            return 0;
        }

        return intval($count);
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
    public static function getBoardValueOptions($custom_table, bool $with_workflow = true)
    {
        $options = [];

        $workflow = $with_workflow ? Workflow::getWorkflowByTable($custom_table) : null;
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
     * Get an image-type custom column belonging to this table.
     *
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getImageColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || array_get($custom_column, 'column_type') != ColumnType::IMAGE) {
            return null;
        }

        return $custom_column;
    }


    /**
     * Get a select-type custom column, multi-value allowed - a card may carry
     * several labels, unlike a board key.
     *
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getSelectableColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || !static::isSelectableColumn($custom_column)) {
            return null;
        }

        return $custom_column;
    }


    /**
     * Get a custom column whose value fits in a small corner badge.
     *
     * @param mixed $column_id
     * @return CustomColumn|null
     */
    protected function getBadgeColumnById($column_id)
    {
        $custom_column = $this->getColumnById($column_id);
        if (!isset($custom_column) || !in_array(array_get($custom_column, 'column_type'), static::badgeColumnTypes())) {
            return null;
        }

        return $custom_column;
    }


    /**
     * Column types short enough to sit in a badge.
     *
     * @return array<int, string>
     */
    protected static function badgeColumnTypes()
    {
        return [
            ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY,
            ColumnType::TEXT, ColumnType::AUTO_NUMBER,
            ColumnType::SELECT, ColumnType::SELECT_VALTEXT,
            ColumnType::DATE, ColumnType::DATETIME,
        ];
    }


    /**
     * How the cover image fills its box.
     *
     * @return string
     */
    protected function getCoverFit()
    {
        return ($this->custom_view->kanban_cover_fit == static::COVER_FIT_CONTAIN)
            ? static::COVER_FIT_CONTAIN : static::COVER_FIT_COVER;
    }


    /**
     * How the label strip is drawn.
     *
     * @return string
     */
    protected function getLabelsStyle()
    {
        return ($this->custom_view->kanban_labels_style == static::LABELS_STYLE_BAR)
            ? static::LABELS_STYLE_BAR : static::LABELS_STYLE_CHIP;
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
     * How a number column must be printed on the board: the same prefix,
     * suffix and digits the data list gives it, so a total on the column head
     * reads exactly like the same figure inside a card.
     *
     * @param CustomColumn|null $custom_column
     * @return array<string, mixed>|null
     */
    protected function buildNumberFormat($custom_column)
    {
        if (!isset($custom_column)) {
            return null;
        }

        $options = (array)array_get($custom_column, 'options', []);

        $prefix = '';
        $suffix = '';
        $symbol = array_get($options, 'currency_symbol');
        if (!is_nullorempty($symbol)) {
            // the enum knows whether the symbol belongs before or after
            $marked = getCurrencySymbolLabel($symbol, false, "\x01");
            if (is_string($marked) && strpos($marked, "\x01") !== false) {
                list($prefix, $suffix) = explode("\x01", $marked, 2);
            }
        }

        return [
            'prefix' => $prefix,
            'suffix' => $suffix,
            'digits' => intval(array_get($options, 'decimal_digit', 0)),
            'group' => boolval(array_get($options, 'number_format', false)),
        ];
    }


    /**
     * The value a progress bar counts as full.
     *
     * @return float
     */
    protected function getProgressMax()
    {
        $max = $this->custom_view->kanban_progress_max;
        if (!isset($max) || !is_numeric($max) || floatval($max) <= 0) {
            return 100;
        }

        return floatval($max);
    }


    /**
     * Values that mark a card, as column name and key pairs.
     *
     * Stored the same way as the done and hide settings - "12::open" - but
     * these are not read against the board columns, so the column each key
     * belongs to has to travel with it.
     *
     * @param string $option_name
     * @return array<int, array<string, string>>
     */
    protected function getValueKeyList(string $option_name)
    {
        $stored = $this->custom_view->{$option_name};
        if (is_nullorempty($stored)) {
            return [];
        }

        $names = [];
        foreach ($this->custom_table->custom_columns_cache as $custom_column) {
            $names[strval($custom_column->id)] = $custom_column->column_name;
        }

        $list = [];
        $seen = [];
        foreach ((array)$stored as $value) {
            $value = strval($value);
            $pos = strpos($value, '::');
            if ($pos === false) {
                continue;
            }
            $prefix = substr($value, 0, $pos);
            $key = substr($value, $pos + 2);
            if ($key === '') {
                continue;
            }
            // A card carries its workflow status only on a workflow board, so a
            // status picked here would mark nothing on a column board. The
            // picker leaves them out for that reason; a view saved before it
            // did can still be holding one.
            //
            // A column dropped from the table leaves its keys behind too, and
            // marking every card whose value happens to match would be worse
            // than marking none.
            if ($prefix === static::WORKFLOW_PREFIX) {
                continue;
            }
            $column = array_get($names, $prefix);
            if (is_nullorempty($column)) {
                continue;
            }
            $pair = $column . '::' . $key;
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            $list[] = ['column' => $column, 'key' => $key];
        }

        return $list;
    }


    /**
     * What a full column does to a card dropped on it.
     *
     * @return string
     */
    protected function getWipEnforce()
    {
        $value = strval($this->custom_view->kanban_wip_enforce);
        if (in_array($value, [static::WIP_ENFORCE_WARN, static::WIP_ENFORCE_BLOCK], true)) {
            return $value;
        }

        // boards saved before the setting existed keep behaving as they did
        return static::WIP_ENFORCE_OFF;
    }


    /**
     * The rule each board column carries, by column key.
     *
     * @param CustomColumn|null $group_column
     * @param Workflow|null $workflow
     * @return array<string, string>
     */
    protected function getPolicies($group_column, $workflow)
    {
        $prefix = $this->getSettingPrefix($group_column, $workflow);

        $policies = [];
        foreach ((array)$this->custom_view->kanban_policies as $row) {
            $key = static::stripSettingPrefix(array_get($row, 'kanban_policy_key'), $prefix);
            $text = trim(strval(array_get($row, 'kanban_policy_text')));
            if (is_null($key) || $text === '') {
                continue;
            }
            $policies[$key] = $text;
        }

        return $policies;
    }


    /**
     * Assignee values that stand for the logged in user.
     *
     * Empty means the board cannot tell: either there is no assignee column,
     * or it holds words rather than people. The "only mine" filter is left out
     * in that case instead of quietly matching nothing.
     *
     * @param CustomColumn|null $assignee_column
     * @return array<int, string>
     */
    protected function getMyKeys($assignee_column)
    {
        if (!isset($assignee_column)) {
            return [];
        }

        $column_type = array_get($assignee_column, 'column_type');
        if ($column_type == ColumnType::SELECT_TABLE) {
            // a select_table only holds people when the table it points at does
            $target = $assignee_column->select_target_table;
            $table_name = isset($target) ? $target->table_name : null;
            if ($table_name == SystemTableName::USER) {
                $column_type = ColumnType::USER;
            } elseif ($table_name == SystemTableName::ORGANIZATION) {
                $column_type = ColumnType::ORGANIZATION;
            }
        }

        $user = \Exment::user();
        if (!isset($user)) {
            return [];
        }

        if ($column_type == ColumnType::USER) {
            $user_id = \Exment::getUserId();
            return is_nullorempty($user_id) ? [] : [strval($user_id)];
        }

        if ($column_type == ColumnType::ORGANIZATION) {
            // on an organisation column "mine" is every organisation the user
            // counts as belonging to, the ones above and below included - the
            // same reach the ordinary grid filter uses
            return collect($user->getOrganizationIdsForQuery())
                ->map(function ($id) {
                    return strval($id);
                })->values()->toArray();
        }

        return [];
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
        // a preview draws settings that are not saved yet: the board a card
        // would be dropped on is not the board the server would write to
        if ($this->preview) {
            return false;
        }

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
        if ($this->preview) {
            return false;
        }

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
        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->first();
        }
        if (is_array($value)) {
            $value = count($value) > 0 ? reset($value) : null;
        }
        // a select_table / user / organization column answers with the related
        // record itself. Its id is both the stored value and the option key -
        // without this the "key" would be the record serialized to json, which
        // never matches anything and would be written back on assign.
        if ($value instanceof CustomValue) {
            return strval($value->id);
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
        $number_options = static::getColumnOptionsByType($custom_table, static::numberColumnTypes());
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

        // trello-style card extras: cover image, colored label strip, corner badge.
        // Each style select only appears once its column is picked.
        $form->select('kanban_cover_column_id', exmtrans("custom_view.kanban_cover_column"))
            ->options(static::getColumnOptionsByType($custom_table, [ColumnType::IMAGE]))
            ->attribute(['data-filtertrigger' => true])
            ->help(exmtrans("custom_view.help.kanban_cover_column"));

        $form->select('kanban_cover_fit', exmtrans("custom_view.kanban_cover_fit"))
            ->options([
                static::COVER_FIT_COVER => exmtrans("custom_view.kanban_cover_fit_options.cover"),
                static::COVER_FIT_CONTAIN => exmtrans("custom_view.kanban_cover_fit_options.contain"),
            ])
            ->default(static::COVER_FIT_COVER)
            ->attribute(['data-filter' => json_encode(['key' => 'kanban_cover_column_id', 'hasValue' => true])])
            ->help(exmtrans("custom_view.help.kanban_cover_fit"));

        $form->select('kanban_labels_column_id', exmtrans("custom_view.kanban_labels_column"))
            ->options(static::getColumnOptionsByType($custom_table, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT]))
            ->attribute(['data-filtertrigger' => true])
            ->help(exmtrans("custom_view.help.kanban_labels_column"));

        $form->select('kanban_labels_style', exmtrans("custom_view.kanban_labels_style"))
            ->options([
                static::LABELS_STYLE_CHIP => exmtrans("custom_view.kanban_labels_style_options.chip"),
                static::LABELS_STYLE_BAR => exmtrans("custom_view.kanban_labels_style_options.bar"),
            ])
            ->default(static::LABELS_STYLE_CHIP)
            ->attribute(['data-filter' => json_encode(['key' => 'kanban_labels_column_id', 'hasValue' => true])])
            ->help(exmtrans("custom_view.help.kanban_labels_style"));

        $form->select('kanban_badge_column_id', exmtrans("custom_view.kanban_badge_column"))
            ->options(static::getColumnOptionsByType($custom_table, static::badgeColumnTypes()))
            ->help(exmtrans("custom_view.help.kanban_badge_column"));

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
            $form->icon('kanban_icon', exmtrans("custom_view.kanban_icon"))
                ->default('')
                ->attribute(['style' => 'width:100%']);
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

        $form->select('kanban_wip_enforce', exmtrans("custom_view.kanban_wip_enforce"))
            ->options([
                static::WIP_ENFORCE_OFF => exmtrans("custom_view.kanban_wip_enforce_options.off"),
                static::WIP_ENFORCE_WARN => exmtrans("custom_view.kanban_wip_enforce_options.warn"),
                static::WIP_ENFORCE_BLOCK => exmtrans("custom_view.kanban_wip_enforce_options.block"),
            ])
            ->default(static::WIP_ENFORCE_OFF)
            ->help(exmtrans("custom_view.help.kanban_wip_enforce"));

        // a rule written on the column is a rule the team reads every day
        $form->hasManyJsonTable('kanban_policies', exmtrans("custom_view.kanban_policies"), function ($form) use ($value_options) {
            $form->select('kanban_policy_key', exmtrans("custom_view.kanban_policy_key"))
                ->required()
                ->options($value_options);
            $form->text('kanban_policy_text', exmtrans("custom_view.kanban_policy_text"))
                ->required();
        })->setTableColumnWidth(4, 7, 1)
        ->help(exmtrans("custom_view.help.kanban_policies"));

        $form->select('kanban_wip_column_id', exmtrans("custom_view.kanban_wip_column"))
            ->options($number_options)
            ->help(exmtrans("custom_view.help.kanban_wip_column"));

        // a running total is a display, not a limit: keeping it apart from the
        // WIP setting is what lets a board show yen and still cap cards
        $form->select('kanban_sum_column_id', exmtrans("custom_view.kanban_sum_column"))
            ->options($number_options)
            ->help(exmtrans("custom_view.help.kanban_sum_column"));

        $form->switchbool('kanban_col_age', exmtrans("custom_view.kanban_col_age"))
            ->default(false)
            ->help(exmtrans("custom_view.help.kanban_col_age"));

        $form->select('kanban_progress_column_id', exmtrans("custom_view.kanban_progress_column"))
            ->options($number_options)
            ->attribute(['data-filtertrigger' => true])
            ->help(exmtrans("custom_view.help.kanban_progress_column"));

        $form->number('kanban_progress_max', exmtrans("custom_view.kanban_progress_max"))
            ->min(1)
            ->max(1000000)
            ->default(100)
            ->attribute(['data-filter' => json_encode(['key' => 'kanban_progress_column_id', 'hasValue' => true])])
            ->help(exmtrans("custom_view.help.kanban_progress_max"));

        $form->multipleSelect('kanban_done_keys', exmtrans("custom_view.kanban_done_keys"))
            ->options($value_options)
            ->help(exmtrans("custom_view.help.kanban_done_keys"));

        // a board that has to show every value it can hold is a board nobody
        // reads: closed work is most of the table and none of the work
        $form->multipleSelect('kanban_hide_keys', exmtrans("custom_view.kanban_hide_keys"))
            ->options($value_options)
            ->help(exmtrans("custom_view.help.kanban_hide_keys"));

        // Work that has stopped looks exactly like work in flight until the
        // board is told which values mean stopped.
        //
        // Real columns only: these two are read against the card, and a card
        // only carries its workflow status on a workflow board. Offering the
        // statuses here would let a column board be given a mark that never
        // appears.
        $mark_options = static::getBoardValueOptions($custom_table, false);

        $form->multipleSelect('kanban_blocked_keys', exmtrans("custom_view.kanban_blocked_keys"))
            ->options($mark_options)
            ->help(exmtrans("custom_view.help.kanban_blocked_keys"));

        $form->multipleSelect('kanban_expedite_keys', exmtrans("custom_view.kanban_expedite_keys"))
            ->options($mark_options)
            ->help(exmtrans("custom_view.help.kanban_expedite_keys"));

        $form->number('kanban_col_count', exmtrans("custom_view.kanban_col_count"))
            ->min(0)
            ->max(500)
            ->default(0)
            ->help(exmtrans("custom_view.help.kanban_col_count"));

        $form->select('kanban_assignee_column_id', exmtrans("custom_view.kanban_assignee_column"))
            ->options($people_options)
            ->help(exmtrans("custom_view.help.kanban_assignee_column"));

        $form->select('kanban_limit_column_id', exmtrans("custom_view.kanban_limit_column"))
            ->options($date_options)
            ->attribute(['data-filtertrigger' => true])
            ->help(exmtrans("custom_view.help.kanban_limit_column"));

        $form->number('kanban_limit_warn', exmtrans("custom_view.kanban_limit_warn"))
            ->min(0)
            ->max(9999)
            ->default(2)
            ->attribute(['data-filter' => json_encode(['key' => 'kanban_limit_column_id', 'hasValue' => true])])
            ->help(exmtrans("custom_view.help.kanban_limit_warn"));

        $form->select('kanban_age_column_id', exmtrans("custom_view.kanban_age_column"))
            ->options($date_options)
            ->attribute(['data-filtertrigger' => true])
            ->help(exmtrans("custom_view.help.kanban_age_column"));

        $form->text('kanban_age_steps', exmtrans("custom_view.kanban_age_steps"))
            ->default('1,2,3')
            ->rules(['nullable', 'regex:/^\s*\d+(\.\d+)?\s*(,\s*\d+(\.\d+)?\s*){2}$/'], [
                'regex' => exmtrans('custom_view.message.kanban_age_steps_format'),
            ])
            ->attribute([
                'data-filter' => json_encode(['key' => 'kanban_age_column_id', 'hasValue' => true]),
                'placeholder' => '1,2,3',
            ])
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
            ->attribute(['data-filtertrigger' => true])
            ->help(exmtrans("custom_view.help.kanban_drawer"));

        if ($has_workflow) {
            $form->switchbool('kanban_history', exmtrans("custom_view.kanban_history"))
                ->default(true)
                ->attribute(['data-filter' => json_encode(['key' => 'kanban_drawer', 'value' => '1'])])
                ->help(exmtrans("custom_view.help.kanban_history"));
        }

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
