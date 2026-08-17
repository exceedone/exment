<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;

/**
 * Floating "N rows selected" bar for the data grid.
 *
 * The stock batch UI is a small button group that appears next to the
 * select-all checkbox in the box header. On a long page the user is
 * ticking rows at the bottom of the table while that button sits far
 * above the fold, so the usual result is a screen full of ticked rows
 * and no visible way to act on them.
 *
 * The bar is a second entrance to that same button group PLUS two
 * generic actions the grid already has webapi endpoints for and that
 * the stock dropdown does not expose:
 *
 *   - Bulk edit: a Redmine-style modal - fill only the fields to change,
 *     the rest stay untouched. Uses the same PUT
 *     `/admin/webapi/data/{table}/{id}` endpoint the single-cell inline
 *     editor uses, so validation / workflow / revision live in one
 *     place. Picker columns only (see isBulkEditableColumn).
 *
 *   - Bulk export: reuse laravel-admin's `_export_=selected:{ids}` scope
 *     against the current grid, so only ticked rows appear in the file
 *     without adding a new controller. One button per enabled format
 *     (CSV / Excel), mirroring the export dialog's config switches.
 *
 * Everything that runs inside a CustomOperation (BatchUpdate, BatchRestore,
 * BatchHardDelete, BatchDelete) is copied from the stock dropdown, so a
 * new batch action added there shows up in the bar without touching this
 * file. Selection itself is never written directly - grid_tools.js drives
 * clearing through the row checkboxes so laravel-admin's own bookkeeping
 * (`$.admin.grid.selected`, the `selected` row class, the iCheck skin)
 * stays correct.
 */
class GridBulkBar extends AbstractTool
{
    /** @var CustomTable|null */
    protected $custom_table;

    /** @var CustomView|null */
    protected $custom_view;

    /**
     * @param CustomTable|null $custom_table
     * @param CustomView|null $custom_view
     */
    public function __construct(CustomTable $custom_table = null, CustomView $custom_view = null)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    /**
     * @return string
     */
    public function render()
    {
        $gridId = e($this->grid->tableID);
        // Class of the stock batch button group, i.e. where the real
        // actions live. Passed along instead of hard coded because
        // laravel-admin lets a grid rename it.
        $allName = e($this->grid->getSelectAllName());
        $label = e(exmtrans('common.grid_bulk_selected'));
        $clear = e(exmtrans('common.grid_bulk_clear'));
        // The stock batch button writes its own counter from this string.
        // It is only ever refreshed from an iCheck pointer event, so when
        // the bar clears the selection - or the page filter unticks a row
        // it hides - the JS has to rewrite it, and it needs the theme's
        // own wording to do that rather than inventing a second one.
        $stockLabel = e(trans('admin.grid_items_selected'));

        // The bar can expose two more actions when the table permits.
        // Bulk edit needs an edit permission AND at least one column the
        // inline editor would pick up - otherwise there is nothing to
        // change. `GridInlineEditor::isEditable` is the source of truth
        // for that list, so the two never drift apart.
        $canEdit = $this->custom_table && $this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
        $hasEditableColumn = $canEdit && $this->hasAnyEditableColumn();
        // Bulk export reuses the standard Exment export route, so it is
        // available exactly when the whole-table export is.
        $canExport = $this->custom_table && $this->custom_table->enableExport() === true;

        $listUrl = $this->custom_table ? e(admin_urls('data', $this->custom_table->table_name)) : '';
        $webapiUrl = $this->custom_table ? e(admin_urls('webapi', 'data', $this->custom_table->table_name)) : '';
        $csrf = e(csrf_token());

        $editBtn = '';
        if ($hasEditableColumn) {
            $editLabel = e(exmtrans('common.grid_bulk_edit'));
            $editBtn = <<<HTML
<button type="button" class="btn btn-sm btn-default exm-bulk-edit"><i class="fa fa-edit"></i>&nbsp;{$editLabel}</button>
HTML;
        }

        $exportBtn = '';
        if ($canExport) {
            // The same two switches the export dialog obeys - a format the
            // administrator turned off must not resurface here.
            $formats = [];
            if (!boolval(config('exment.export_import_export_disabled_csv', false))) {
                $formats['csv'] = exmtrans('common.grid_bulk_export_csv');
            }
            if (!boolval(config('exment.export_import_export_disabled_excel', false))) {
                $formats['xlsx'] = exmtrans('common.grid_bulk_export_xlsx');
            }

            $exportLabel = e(exmtrans('common.grid_bulk_export'));
            if (count($formats) === 1) {
                // Only one way out - a format suffix would just be noise.
                $fmt = e((string)array_key_first($formats));
                $exportBtn = <<<HTML
<button type="button" class="btn btn-sm btn-default exm-bulk-export" data-format="{$fmt}"><i class="fa fa-download"></i>&nbsp;{$exportLabel}</button>
HTML;
            } else {
                foreach ($formats as $fmt => $fmtLabel) {
                    $fmt = e((string)$fmt);
                    $fmtLabel = e($fmtLabel);
                    $exportBtn .= <<<HTML
<button type="button" class="btn btn-sm btn-default exm-bulk-export" data-format="{$fmt}" title="{$exportLabel} ({$fmtLabel})"><i class="fa fa-download"></i>&nbsp;{$fmtLabel}</button>
HTML;
                }
            }
        }

        return <<<HTML
<div class="exm-bulkbar" data-grid="{$gridId}"
    data-all="{$allName}"
    data-label="{$label}"
    data-stock-label="{$stockLabel}"
    data-list-url="{$listUrl}"
    data-webapi-url="{$webapiUrl}"
    data-csrf="{$csrf}">
    <span class="exm-bulk-count"></span>
    <span class="exm-bulk-actions">{$editBtn}{$exportBtn}</span>
    <a href="#" class="exm-bulk-clear"><i class="fa fa-times"></i>&nbsp;{$clear}</a>
</div>
HTML;
    }

    /**
     * Whether at least one column on the current view can appear in the
     * bulk edit modal. Deliberately the BULK check, not the inline one:
     * the free-typed columns the inline editor now accepts (text, number,
     * date) are excluded from the modal - see isBulkEditableColumn - so a
     * view made up of only those would get a bulk edit button that opens
     * an empty modal.
     *
     * @return bool
     */
    protected function hasAnyEditableColumn(): bool
    {
        if (!$this->custom_view) {
            return false;
        }
        foreach ($this->custom_view->custom_view_columns_cache as $custom_view_column) {
            if (GridInlineEditor::isBulkEditableColumn($custom_view_column, $this->custom_table)) {
                return true;
            }
        }
        return false;
    }
}

