<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;

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
 *   - Date, Datetime -> the browser's own calendar input
 *
 * The typed inputs carry no client-side rules beyond the html input
 * type: the PUT lands in the same ApiDataController validation the edit
 * form uses, and a refused value comes back as a failed save (red flash,
 * old markup restored). Multi-select stays out - it would need a
 * completely different editor (chips, add/remove per value). A column
 * outside the list keeps its normal double-click behaviour (nothing, the
 * row's link handler still runs), so the pen icon simply never shows.
 *
 * The config also carries the update URL, the cell URL template and a
 * fresh CSRF token; grid_tools.js never fabricates URLs from string
 * concatenation.
 */
class GridInlineEditor extends AbstractTool
{
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
     * @return string
     */
    public function render()
    {
        // No edit right, no editor. The `.exm-editable` class never made
        // it onto the cells either, so grid_tools.js has nothing to
        // react to - the config would be dead weight.
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
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
            'csrf' => csrf_token(),
            'columns' => $columns,
            'labels' => [
                'saving' => exmtrans('common.grid_inline_saving'),
                'saved' => exmtrans('common.grid_inline_saved'),
                'error' => exmtrans('common.grid_inline_error'),
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
            // A computed column recalculates from its formula on save, so a
            // hand-typed value would only survive until the next write -
            // an editor that looks like it works and then loses the value
            // is worse than no editor.
            return is_nullorempty($custom_column->getOption('calc_formula'));
        }

        return false;
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

        if (!$needed && isset($html)
            && method_exists($item, 'getCustomColumn')
            && ($custom_column = $item->getCustomColumn())
            && in_array($custom_column->column_type, [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY], true)) {
            // Same recovery the client runs on the cell text, then the same
            // trailing-zero blindness ("9800.00" and "9800" are one number).
            $shown = preg_replace(
                '/[^0-9.\-]/',
                '',
                html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)
            );
            $needed = static::normalizeNumber($shown) !== static::normalizeNumber((string)$value);
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
