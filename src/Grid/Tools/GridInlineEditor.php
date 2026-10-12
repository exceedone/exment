<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\FormDataType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

/**
 * Configuration carrier for the data grid inline editor.
 *
 * Renders nothing visible - only a `<script type="application/json">`
 * that grid_tools.js reads on boot. The editor's UI lives entirely on
 * the client: a `<td>` marked `.exm-editable` is turned into an in-place
 * `<select>` on double click, submits through the existing
 * `PUT /admin/webapi/data/{table}/{id}` endpoint and reloads its own
 * markup from `GET .../cell/{id}/{column}`. That keeps the change
 * inside the same webapi guard chain the row's edit form goes through
 * (adminweb + adminwebapi middleware, ApiDataController validation,
 * workflow, revision) - the grid is not writing to the database on its
 * own.
 *
 * The config lists the columns the editor knows how to open:
 *
 *   - Select and SelectValtext (single value, no free input) and Yesno
 *     -> a picker
 *   - Text, Email, Url -> a text input
 *   - Integer, Decimal, Currency -> a number input; skipped when the
 *     column is computed by a calc formula, because a hand-typed value
 *     would just be overwritten by the next recalculation
 *   - Date, Datetime -> a text input with Exment's own calendar (the one
 *     the edit form uses), never the browser's date input
 *
 * A column a calc formula reads is skipped too (isCalcInput), and so is
 * one that no form of the table offers an input for; whether the form of
 * a given record does is decided per row (LOCKED_MARKER) and again by the
 * server, which applies the form rules to every write that carries
 * SCREEN_WRITE_KEY.
 *
 * The typed inputs carry no client-side rules beyond the html input
 * type: the PUT lands in the same ApiDataController validation the edit
 * form uses, and a refused value comes back as a failed save (red flash,
 * old markup restored, the server's reason shown). Multi-select stays
 * out - it would need a completely different editor (chips, add/remove
 * per value). A column
 * outside the list keeps its normal double-click behaviour (nothing, the
 * row's link handler still runs), so the pen icon simply never shows.
 *
 * The config also carries the update URL, the cell URL template and a
 * fresh CSRF token; grid_tools.js never fabricates URLs from string
 * concatenation.
 */
class GridInlineEditor extends AbstractTool
{
    use TrashedScopeTrait;

    /**
     * Body key every write from a list screen carries: inline edit, bulk
     * edit, "Set me as" and a kanban move.
     *
     * Those writes go through the webapi endpoint that is also the plain
     * API, where a caller writes columns, not forms. The key tells
     * ApiDataController that the write stands in for the record's edit page,
     * so the rules that page enforces apply to it too - see
     * validateScreenWrite().
     */
    public const SCREEN_WRITE_KEY = '_grid_edit';

    /**
     * Marker DefaultGrid puts in a cell of an editable column when the form
     * of THAT record does not let the user change it. The column is editable
     * on some records and not on others (form priorities), so the class on
     * the column cannot say it; grid_tools.js drops the cell's editor when it
     * finds this.
     */
    public const LOCKED_MARKER = '<span class="exm-cell-locked" hidden></span>';

    /** @var CustomTable */
    protected $custom_table;

    /** @var CustomView */
    protected $custom_view;

    /**
     * @param CustomTable $custom_table
     * @param CustomView $custom_view
     */
    public function __construct(CustomTable $custom_table, CustomView $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    /**
     * May this grid be inline-edited at all, before looking at any column?
     *
     * The single source of truth for that question. DefaultGrid asks it
     * before hanging `.exm-editable` on a cell and GridBulkBar asks it
     * before offering bulk edit, so the class, the JSON config and the
     * bulk button can never disagree - a cell marked editable but missing
     * from the config would open nothing on double click.
     *
     * enableEdit(true) rather than the bare permission: an administrator
     * who turned Edit off for this table meant "not from a screen", and a
     * grid is a screen. The form-action check goes with it, otherwise the
     * editor becomes the one way left to change a record from the screen
     * that setting disabled.
     *
     * The trashed scope is excluded because every row on it is already
     * deleted: the webapi resolves the record without `withTrashed()`, so
     * the PUT would come back "not found" and the user would get a bare
     * error toast instead of a saved cell.
     *
     * @param CustomTable|null $custom_table
     * @return bool
     */
    public static function isEditableGrid($custom_table): bool
    {
        return $custom_table instanceof CustomTable
            && $custom_table->enableEdit(true) === true
            && !self::isTrashedScope($custom_table);
    }

    /**
     * @return string
     */
    public function render()
    {
        // No edit right, no editor. The `.exm-editable` class never made
        // it onto the cells either, so grid_tools.js has nothing to
        // react to - the config would be dead weight.
        if (!self::isEditableGrid($this->custom_table)) {
            return '';
        }

        $columns = $this->collectColumnMeta();
        if (empty($columns)) {
            return '';
        }

        $table_name = $this->custom_table->table_name;
        // Base URLs. The CELL URL is a template - `{id}` and `{column}` are
        // substituted on the client so the file has no per-row bloat.
        $update_url_base = admin_urls('webapi', 'data', $table_name);
        $cell_url_base = admin_urls('webapi', 'data', $table_name, 'cell');

        $config = [
            'tableName' => $table_name,
            'grid' => $this->grid->tableID,
            // The client PUTs to `<updateUrl>/<id>`. It also POSTs to the
            // same base to force a permission recheck if it ever needs one.
            'updateUrl' => $update_url_base,
            // Client fills in `<cellUrl>/<id>/<column>`.
            'cellUrl' => $cell_url_base,
            // ...and appends this view, because the appearance of a cell can
            // be a setting of the view rather than of the column.
            'cellView' => $this->custom_view->suuid ?? null,
            'csrf' => csrf_token(),
            // The date editor uses Exment's own calendar, which follows the
            // locale it is given rather than the browser's.
            'locale' => \App::getLocale(),
            'columns' => $columns,
            'labels' => [
                'saving' => exmtrans('common.grid_inline_saving'),
                'saved' => exmtrans('common.grid_inline_saved'),
                'error' => exmtrans('common.grid_inline_error'),
                'stale' => exmtrans('common.grid_inline_stale'),
                'edit' => exmtrans('common.grid_inline_edit'),
                // Bulk-edit modal shares the inline editor's config
                // (columns, choices, update URL, CSRF), so its user
                // strings live here too - one source of truth for the
                // two features that speak to the same webapi endpoint.
                'bulk_edit_title' => exmtrans('common.grid_bulk_edit_title'),
                'bulk_edit_pick_column' => exmtrans('common.grid_bulk_edit_pick_column'),
                'bulk_edit_pick_fields' => exmtrans('common.grid_bulk_edit_pick_fields'),
                'bulk_edit_no_change' => exmtrans('common.grid_bulk_edit_no_change'),
                'bulk_edit_nothing' => exmtrans('common.grid_bulk_edit_nothing'),
                'bulk_edit_column' => exmtrans('common.grid_bulk_edit_column'),
                'bulk_edit_value' => exmtrans('common.grid_bulk_edit_value'),
                'bulk_edit_apply' => exmtrans('common.grid_bulk_edit_apply'),
                'bulk_edit_cancel' => exmtrans('common.grid_bulk_edit_cancel'),
                'bulk_edit_confirm' => exmtrans('common.grid_bulk_edit_confirm'),
                'bulk_edit_progress' => exmtrans('common.grid_bulk_edit_progress'),
                'bulk_edit_summary_ok' => exmtrans('common.grid_bulk_edit_summary_ok'),
                'bulk_edit_summary_partial' => exmtrans('common.grid_bulk_edit_summary_partial'),
                'bulk_edit_close' => exmtrans('common.grid_bulk_edit_close'),
            ],
        ];

        // The script tag itself has no visible box in the toolbar - a
        // wrapper `<span class="d-none">` keeps whatever layout math the
        // theme does over `.pull-right` unaffected.
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );

        $gridId = e($this->grid->tableID);

        return <<<HTML
<span class="d-none exm-grid-tool"><script type="application/json" class="exm-inline-config" data-grid="{$gridId}">{$json}</script></span>
HTML;
    }

    /**
     * Walk the current view's columns and keep the ones the inline editor
     * can safely turn into a picker.
     *
     * Column type is what decides: everything else (min-width, style,
     * required) rides on top of it.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function collectColumnMeta(): array
    {
        $result = [];

        foreach ($this->custom_view->custom_view_columns_cache as $custom_view_column) {
            if (!static::isEditableColumn($custom_view_column, $this->custom_table)) {
                continue;
            }

            $custom_column = $custom_view_column->column_item->getCustomColumn();

            $meta = $this->buildColumnMeta($custom_column);
            if (!$meta) {
                continue;
            }

            // Same key the JS reads from the cell's `column-<name>` class,
            // so the two agree without any translation step.
            $result[$custom_column->column_name] = $meta;
        }

        return $result;
    }

    /**
     * @param mixed $custom_column
     * @return array<string, mixed>|null
     */
    protected function buildColumnMeta($custom_column): ?array
    {
        $type = $custom_column->column_type;

        if ($type === ColumnType::SELECT || $type === ColumnType::SELECT_VALTEXT) {
            if (boolval($custom_column->getOption('multiple_enabled'))) {
                return null;
            }
            // Free input turns the field into "options plus anything you
            // type", which a plain picker cannot represent. But the option
            // only takes effect while the radio/checkbox display is off -
            // Select::isFreeInput() ignores it otherwise - and this gate has
            // to match what the form actually does, not what the stored
            // option says.
            if (boolval($custom_column->getOption('free_input'))
                && !boolval($custom_column->getOption('check_radio_enabled'))) {
                return null;
            }

            $options = $custom_column->createSelectOptions();
            $choices = [];
            foreach ($options as $k => $v) {
                $choices[] = [
                    'v' => (string)$k,
                    'l' => (string)$v,
                ];
            }
            if (empty($choices)) {
                return null;
            }

            return [
                'type' => 'select',
                'choices' => $choices,
                'required' => boolval($custom_column->required),
                'label' => (string)$custom_column->column_view_name,
            ];
        }

        if ($type === ColumnType::YESNO) {
            return [
                'type' => 'select',
                'choices' => [
                    ['v' => '0', 'l' => (string)getYesNo(0)],
                    ['v' => '1', 'l' => (string)getYesNo(1)],
                ],
                'required' => true, // yesno stores 0 or 1, empty is not a valid state
                'label' => (string)$custom_column->column_view_name,
            ];
        }

        if (in_array($type, [ColumnType::TEXT, ColumnType::EMAIL, ColumnType::URL], true)) {
            $meta = [
                'type' => 'text',
                'required' => boolval($custom_column->required),
                'label' => (string)$custom_column->column_view_name,
            ];
            // The one client-side rule worth carrying: maxlength stops the
            // overrun while typing instead of after the round trip.
            $length = $custom_column->getOption('string_length');
            if (!is_nullorempty($length)) {
                $meta['maxLength'] = intval($length);
            }
            return $meta;
        }

        if (in_array($type, [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY], true)) {
            $meta = [
                'type' => 'number',
                // Drives the input's step only - whole numbers spin by 1,
                // the rest accept any fraction.
                'decimal' => $type !== ColumnType::INTEGER,
                'required' => boolval($custom_column->required),
                'label' => (string)$custom_column->column_view_name,
            ];
            // Same min/max the edit form puts on its own number field.
            $min = $custom_column->getOption('number_min');
            $max = $custom_column->getOption('number_max');
            if (!is_nullorempty($min)) {
                $meta['min'] = $min;
            }
            if (!is_nullorempty($max)) {
                $meta['max'] = $max;
            }
            return $meta;
        }

        if ($type === ColumnType::DATE || $type === ColumnType::DATETIME) {
            return [
                'type' => $type === ColumnType::DATE ? 'date' : 'datetime',
                'required' => boolval($custom_column->required),
                'label' => (string)$custom_column->column_view_name,
            ];
        }

        return null;
    }

    /**
     * Whether ONE COLUMN OF ONE VIEW may be edited in place.
     *
     * This is the single answer three places need - the config built
     * above, the `.exm-editable` class DefaultGrid hangs on the cells, and
     * the bulk edit button GridBulkBar shows or hides. They must agree: a
     * cell the user can double-click but that the config does not list
     * opens nothing, and a bulk edit button with no columns behind it
     * opens an empty modal.
     *
     * Two of the three conditions are about WHERE the column lives, not
     * about its type:
     *
     *   - A view may show a column of a RELATED table (`view_column_table_id`
     *     pointing elsewhere). The editor only ever PUTs to
     *     `webapi/data/<this table>/<id>`, so writing such a column would
     *     address the wrong table entirely - a validation error at best,
     *     and a silent write into a same-named column of this table at
     *     worst.
     *   - A pivot column is the same column reached through a relation,
     *     so it carries the same problem plus a row id that is not this
     *     row's.
     *
     * Both are therefore refused here rather than in each caller.
     *
     * @param mixed $custom_view_column
     * @param CustomTable|null $custom_table table the grid is writing to
     * @return bool
     */
    public static function isEditableColumn($custom_view_column, ?CustomTable $custom_table = null): bool
    {
        if (!isset($custom_view_column)) {
            return false;
        }

        // Reached through a relation - not a column of the row on screen.
        if (!is_nullorempty($custom_view_column->view_pivot_column_id ?? null)) {
            return false;
        }

        $item = $custom_view_column->column_item ?? null;
        // Only ColumnItems\CustomItem (and its subclasses) carry a
        // CustomColumn: SystemItem, ParentItem, etc. hold their own notion
        // of "column" that has no `select_item_valtext`, no
        // `multiple_enabled` toggle etc. and can't be inline-edited by this
        // tool. `getCustomColumn()` is the public accessor exposed on
        // CustomItem; falling through when absent keeps the view free of a
        // fatal on those column types.
        if (!isset($item) || !method_exists($item, 'getCustomColumn')) {
            return false;
        }

        $custom_column = $item->getCustomColumn();
        if (!isset($custom_column)) {
            return false;
        }

        // Belongs to another table - see the note above.
        if (isset($custom_table) && $custom_column->custom_table_id != $custom_table->id) {
            return false;
        }

        // No form of this table offers an input for it, so no record can be
        // edited in it from a screen. Whether a given record can is decided
        // per row (LOCKED_MARKER) and again by the server.
        if (isset($custom_table) && !static::isEditableOnSomeForm($custom_table, $custom_column)) {
            return false;
        }

        return static::isEditable($custom_column);
    }

    /**
     * Whether the column's TYPE can be turned into a picker. Callers
     * working from a view column should use `isEditableColumn()` instead -
     * it adds the checks that the column really belongs to the row being
     * edited.
     *
     * @param mixed $custom_column
     * @return bool
     */
    public static function isEditable($custom_column): bool
    {
        if (!isset($custom_column)) {
            return false;
        }

        // "Only set when the record is created". The grid never creates one,
        // and InitOnlyRule refuses every change to such a column on a row
        // that already exists, so an editor here could only ever be refused.
        // Same reading as the calculated columns below: an editor that looks
        // like it works and then loses the value is worse than no editor.
        if (boolval($custom_column->getOption('init_only'))) {
            return false;
        }

        // A calc formula reads it - see isCalcInput().
        if (static::isCalcInput($custom_column)) {
            return false;
        }

        $type = $custom_column->column_type;

        if ($type === ColumnType::SELECT || $type === ColumnType::SELECT_VALTEXT) {
            if (boolval($custom_column->getOption('multiple_enabled'))) {
                return false;
            }
            // Same reading of free_input as buildColumnMeta above: it only
            // counts while the radio/checkbox display is off.
            if (boolval($custom_column->getOption('free_input'))
                && !boolval($custom_column->getOption('check_radio_enabled'))) {
                return false;
            }
            $options = $custom_column->createSelectOptions();
            return !empty($options);
        }

        if ($type === ColumnType::YESNO) {
            return true;
        }

        if (in_array($type, [ColumnType::TEXT, ColumnType::EMAIL, ColumnType::URL,
            ColumnType::DATE, ColumnType::DATETIME, ], true)) {
            return true;
        }

        if (in_array($type, [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY], true)) {
            // A computed column gets its value from its formula, worked out
            // by the edit form. A hand-typed result would disagree with its
            // own inputs and be replaced the next time the form is saved -
            // an editor that looks like it works and then loses the value
            // is worse than no editor.
            return is_nullorempty($custom_column->getOption('calc_formula'));
        }

        return false;
    }

    /**
     * Whether a calc formula of the column's own table reads the column.
     *
     * Calculated columns are only ever computed in the browser, by the edit
     * form: CalcService hands the formulas to its JavaScript, and nothing on
     * the server works them out again. A list screen that changes one of the
     * inputs would leave the result stale - quantity changed, amount still
     * the old total - with no error anywhere. Such a column is therefore not
     * offered there; it is changed on the edit page, which recalculates.
     *
     * Only the formula items that read this row count: `value` (a column of
     * the row) and `select_table` (a reference column of the row, through
     * which a column of the referenced record is read). `sum` and `count`
     * read child rows and `parent` the parent row; the edit form does not
     * recalculate on a change made elsewhere for those either.
     *
     * @param CustomColumn|null $custom_column
     * @return bool
     */
    public static function isCalcInput($custom_column): bool
    {
        if (!isset($custom_column)) {
            return false;
        }
        $custom_table = $custom_column->custom_table_cache;
        if (!isset($custom_table)) {
            return false;
        }

        $inputs = System::requestSession('grid_edit_calc_inputs_' . $custom_table->id, function () use ($custom_table) {
            $ids = [];
            foreach ($custom_table->custom_columns_cache as $formula_column) {
                $formula = $formula_column->getOption('calc_formula');
                if (!is_string($formula) || is_nullorempty($formula)) {
                    continue;
                }
                // Same item syntax CalcService parses: ${value:<column>} and
                // ${select_table:<reference column>.<column there>}.
                preg_match_all('/\$\{(?:value|select_table):(?<key>.+?)\}/i', $formula, $matched);
                foreach ($matched['key'] as $key) {
                    $input = CustomColumn::getEloquent(explode('.', $key)[0], $custom_table);
                    if (isset($input)) {
                        $ids[$input->id] = true;
                    }
                }
            }
            return $ids;
        });

        return is_array($inputs) && array_key_exists($custom_column->id, $inputs);
    }

    /**
     * The form the record's own edit page opens with.
     *
     * Same order as CustomTable::getPriorityForm() - the first form priority
     * whose conditions match the record, else the table's default form -
     * with two differences on purpose:
     *
     *   - the request cannot choose the form. getPriorityForm() honours a
     *     `formid` value (the QR and JAN code links use it), which here
     *     would let a write pick the least restrictive form of the table;
     *   - the conditions are read as on the page the write stands in for:
     *     the EDIT page for a record that exists, the CREATE page for a new
     *     one (null, or a record not saved yet - matched against a blank
     *     record, as getPriorityForm() does on that page). A priority keyed
     *     on the page type would otherwise look at the list page and never
     *     match.
     *
     * @param CustomTable $custom_table
     * @param CustomValue|null $custom_value null for a record about to be created
     * @return CustomForm|null
     */
    public static function getRecordForm(CustomTable $custom_table, ?CustomValue $custom_value): ?CustomForm
    {
        $custom_form_priorities = $custom_table->custom_form_priorities;
        if ($custom_form_priorities->count() > 0) {
            $creating = !isset($custom_value) || !isset($custom_value->id);
            $record = $creating ? $custom_table->getValueModel() : $custom_value;
            $data_type = $creating ? FormDataType::CREATE : FormDataType::EDIT;
            $key = 'grid_edit_record_form_' . $custom_table->id . '_' . ($creating ? 'new' : $custom_value->id);
            $custom_form = System::requestSession($key, function () use ($custom_form_priorities, $record, $data_type) {
                $previous = System::requestSession(Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE);
                System::setRequestSession(Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE, $data_type);
                try {
                    foreach ($custom_form_priorities->sortBy('order') as $custom_form_priority) {
                        if ($custom_form_priority->isMatchCondition($record)) {
                            return $custom_form_priority->custom_form;
                        }
                    }
                    return false;
                } finally {
                    System::setRequestSession(Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE, $previous);
                }
            });
            if ($custom_form instanceof CustomForm) {
                return $custom_form;
            }
        }

        return static::getDefaultForm($custom_table);
    }

    /**
     * The table's default form, read once per request: the list asks for it
     * once per row.
     *
     * @param CustomTable $custom_table
     * @return CustomForm|null
     */
    protected static function getDefaultForm(CustomTable $custom_table): ?CustomForm
    {
        $custom_form = System::requestSession('grid_edit_default_form_' . $custom_table->id, function () use ($custom_table) {
            return CustomForm::getDefault($custom_table);
        });
        return $custom_form instanceof CustomForm ? $custom_form : null;
    }

    /**
     * What a form lets its user change: column id => whether the form makes
     * the column required.
     *
     * A column the form does not show, or shows view only, read only, hidden
     * or internal, is left out - the edit page offers no input for it, so
     * neither may a list screen. Only the form's own block counts; the blocks
     * of related tables write other tables.
     *
     * @param CustomForm|null $custom_form
     * @return array<int|string, bool>
     */
    public static function getFormEditableColumns(?CustomForm $custom_form): array
    {
        if (!isset($custom_form)) {
            return [];
        }

        $columns = System::requestSession('grid_edit_form_columns_' . $custom_form->id, function () use ($custom_form) {
            $result = [];
            foreach ($custom_form->custom_form_blocks_cache as $custom_form_block) {
                if ($custom_form_block->form_block_type != FormBlockType::DEFAULT) {
                    continue;
                }
                foreach ($custom_form_block->custom_form_columns_cache as $custom_form_column) {
                    if ($custom_form_column->form_column_type != FormColumnType::COLUMN) {
                        continue;
                    }
                    $options = $custom_form_column->options ?? [];
                    // The same four switches CustomItem reads, in both of the
                    // shapes a form column may store them in.
                    $showing = array_get($options, 'field_showing_type');
                    foreach (['view_only', 'read_only', 'hidden', 'internal'] as $locked) {
                        if (array_boolval($options, $locked) || $showing == $locked) {
                            continue 2;
                        }
                    }
                    $result[$custom_form_column->form_column_target_id] = array_boolval($options, 'required');
                }
            }
            return $result;
        });

        return is_array($columns) ? $columns : [];
    }

    /**
     * Whether the record's edit page offers an input for the column - the
     * question every list screen write has to answer the way that page
     * would.
     *
     * @param CustomTable $custom_table
     * @param CustomValue|null $custom_value
     * @param CustomColumn $custom_column
     * @return bool
     */
    public static function isFormEditableColumn(CustomTable $custom_table, ?CustomValue $custom_value, CustomColumn $custom_column): bool
    {
        if ($custom_column->custom_table_id != $custom_table->id) {
            return false;
        }

        // "Only set when the record is created": once the record exists the
        // edit page shows the value as text, and InitOnlyRule refuses a change.
        if (isset($custom_value) && isset($custom_value->id) && boolval($custom_column->getOption('init_only'))) {
            return false;
        }

        $columns = static::getFormEditableColumns(static::getRecordForm($custom_table, $custom_value));
        return array_key_exists($custom_column->id, $columns);
    }

    /**
     * Whether at least one form a record of this table can open with offers
     * an input for the column: the default form or a form some priority
     * picks. This is the column-wide answer - the pen in the header, the
     * bulk edit list - before any particular record is looked at.
     *
     * @param CustomTable $custom_table
     * @param CustomColumn $custom_column
     * @return bool
     */
    public static function isEditableOnSomeForm(CustomTable $custom_table, CustomColumn $custom_column): bool
    {
        $forms = [static::getDefaultForm($custom_table)];
        foreach ($custom_table->custom_form_priorities as $custom_form_priority) {
            $forms[] = $custom_form_priority->custom_form;
        }

        foreach ($forms as $custom_form) {
            if ($custom_form instanceof CustomForm
                && array_key_exists($custom_column->id, static::getFormEditableColumns($custom_form))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The form rules for a write that stands in for the record's edit page
     * (see SCREEN_WRITE_KEY): every posted column must be one that page
     * offers an input for and no calc formula reads, and a column the form
     * makes required may not be emptied.
     *
     * A new record (null) stands in for the create page instead, which does
     * not submit until every column the form requires holds something: a
     * required column the screen did not send is an error too, unless the
     * column's own default fills it in, as it would on that page.
     *
     * Returns column name => message, empty when the write may go ahead.
     *
     * @param CustomTable $custom_table
     * @param CustomValue|null $custom_value null for a record about to be created
     * @param array<mixed> $values posted `value`, column name => value
     * @return array<string, string>
     */
    public static function validateScreenWrite(CustomTable $custom_table, ?CustomValue $custom_value, array $values): array
    {
        $errors = [];
        $form_columns = static::getFormEditableColumns(static::getRecordForm($custom_table, $custom_value));

        if (!isset($custom_value)) {
            $filled = $custom_table->setDefaultValue($values);
            foreach ($form_columns as $column_id => $required) {
                if (!$required) {
                    continue;
                }
                $custom_column = $custom_table->custom_columns_cache->first(function ($c) use ($column_id) {
                    return $c->id == $column_id;
                });
                if (isset($custom_column) && !array_key_exists($custom_column->column_name, $values)
                    && is_nullorempty(array_get($filled, $custom_column->column_name))) {
                    $errors[$custom_column->column_name] = trans('validation.required', ['attribute' => strval($custom_column->column_view_name)]);
                }
            }
        }

        foreach ($values as $column_name => $value) {
            $column_name = strval($column_name);
            // By name only. getEloquent() reads a numeric key as an id of
            // any table, which is not what a screen ever sends.
            $custom_column = is_numeric($column_name) ? null : CustomColumn::getEloquent($column_name, $custom_table);
            if (!isset($custom_column) || $custom_column->custom_table_id != $custom_table->id) {
                $errors[$column_name] = exmtrans('common.grid_inline_locked', ['column' => $column_name]);
                continue;
            }

            $label = strval($custom_column->column_view_name);
            if (!static::isFormEditableColumn($custom_table, $custom_value, $custom_column)) {
                $errors[$column_name] = exmtrans('common.grid_inline_locked', ['column' => $label]);
                continue;
            }
            if (static::isCalcInput($custom_column)) {
                $errors[$column_name] = exmtrans('common.grid_inline_calc_input', ['column' => $label]);
                continue;
            }
            if (array_get($form_columns, $custom_column->id) === true && is_nullorempty($value)) {
                $errors[$column_name] = trans('validation.required', ['attribute' => $label]);
            }
        }

        return $errors;
    }

    /**
     * The hidden marker an editable cell carries its true value in, or ''
     * when the cell does not need one.
     *
     * The grid shortens a long value to `exment.grid_mat_length`
     * characters and appends '...' (`get_omitted_string`). That is right
     * for reading and wrong for editing: the client prefills the editor
     * from the cell it is opening, so on a shortened cell the editor
     * would come up holding '...' and save that string back - silently
     * dropping everything past the 50th character of the stored value.
     *
     * Shortening is not the only lossy formatting. A number column with
     * number_format rounds for display (1000.999 with one decimal digit
     * paints as "1,001"), and percent_format multiplies by 100 and
     * appends '%' - in both, the digits on screen are NOT the stored
     * value, and an editor prefilled from them would save the display
     * back over the data. So a marker is also written whenever
     * recovering a number from the cell the way the client does (strip
     * everything but digits, dot and minus) does not land back on the
     * stored value.
     *
     * Date and datetime columns get one unconditionally. Their display
     * format is a system setting with a locale-dependent list, and a
     * column may override it, so there is no single shape the client
     * could read a date back from - see the note at the check itself.
     *
     * Only those cells get a marker. It is the same data twice in the
     * page, and the vast majority of cells are neither shortened nor
     * reformatted - there the text the user is reading IS the value.
     *
     * The value written out is the DATABASE value, not the display text:
     * a select stores the option key and that is what the editor's
     * <option> carries, a date stores 'Y-m-d H:i:s' which the client
     * already parses. Multi-value columns return an array here, but they
     * are not inline editable in the first place, so they get nothing.
     *
     * @param mixed $item column item, already bound to a row
     * @param string|null $html the cell markup being sent, when the caller
     *        has it - it is what the client's own recovery would read
     * @return string
     */
    public static function rawValueTag($item, ?string $html = null): string
    {
        if (!isset($item)) {
            return '';
        }

        $value = $item->pureValue();
        if (is_null($value) || is_array($value) || is_object($value)) {
            return '';
        }

        $needed = false;

        $text = $item->text();
        if (is_string($text) && $text !== get_omitted_string($text)) {
            // The grid shortened it - the tail is not on screen at all.
            $needed = true;
        }

        $custom_column = (!$needed && method_exists($item, 'getCustomColumn'))
            ? $item->getCustomColumn()
            : null;

        if (!$needed && $custom_column
            && in_array($custom_column->column_type, [ColumnType::DATE, ColumnType::DATETIME], true)) {
            // A date is always written out, whatever it looks like on screen.
            // The display format is a setting - 'Y-m-d', 'Y/m/d', the Japanese
            // 'Y年m月d日' and, in the English locale, the day-first 'd/m/Y'
            // (system.date_format_list) - and a column may override it with
            // any format of its own. The client cannot read them all back, and
            // a date it fails to read opens the editor empty: the stored date
            // then looks like no date at all, so clearing the cell sends
            // nothing and the value cannot be removed inline. Handing over the
            // database value settles every format at once.
            $needed = true;
        }

        $is_number = $custom_column
            && in_array($custom_column->column_type, [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY], true);

        if (!$needed && isset($html) && $is_number) {
            // Same recovery the client runs on the cell text, then the same
            // trailing-zero blindness ("9800.00" and "9800" are one number).
            $shown = preg_replace(
                '/[^0-9.\-]/',
                '',
                html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)
            );
            $needed = static::normalizeNumber($shown) !== static::normalizeNumber((string)$value);
        }

        if (!$needed && isset($html) && !$is_number && is_string($text)) {
            // A cell style can add text of its own around the value: the
            // avatar style prints the initial in front of a name, so a cell
            // holding "Tanaka" reads "TTanaka". The client prefills from the
            // text it can read, the same way as here - tags dropped, spaces
            // collapsed - so whenever that text is not the value's own text,
            // the editor would open on a value nobody stored.
            $collapse = function (string $s): string {
                return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
            };
            $shown = $collapse(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
            $needed = $shown !== $collapse($text);
        }

        if (!$needed) {
            return '';
        }

        return '<span class="exm-cell-raw" data-v="' . esc_html((string)$value) . '"></span>';
    }

    /**
     * '1,001.50' and '1001.5' are the same number; '1001' and '1000.999'
     * are not. String maths on purpose - floats would call 16-digit
     * values equal at exactly the precision this marker exists to keep.
     *
     * @param string $number
     * @return string
     */
    protected static function normalizeNumber(string $number): string
    {
        $number = trim($number);
        if (strpos($number, '.') !== false) {
            $number = rtrim(rtrim($number, '0'), '.');
        }
        return $number;
    }

    /**
     * Whether a view column may appear in the BULK edit modal.
     *
     * Stricter than `isEditableColumn` on purpose: the modal rests on a
     * sentinel "(no change)" option, and only a <select> can carry one. A
     * free-typed input's untouched empty state is indistinguishable from
     * "clear this column on every selected row" - the loudest possible
     * way to lose data - so only the picker types qualify. This is the
     * server-side twin of the `meta.type === 'select'` filter the modal
     * applies, and GridBulkBar uses it so the bulk edit button never
     * opens an empty modal.
     *
     * @param mixed $custom_view_column
     * @param CustomTable|null $custom_table
     * @return bool
     */
    public static function isBulkEditableColumn($custom_view_column, ?CustomTable $custom_table = null): bool
    {
        if (!static::isEditableColumn($custom_view_column, $custom_table)) {
            return false;
        }

        $type = $custom_view_column->column_item->getCustomColumn()->column_type;
        return in_array($type, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT, ColumnType::YESNO], true);
    }
}
