<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;

/**
 * Right-click menu for a data grid row.
 *
 * Renders one hidden `<div class="exm-ctxmenu">` with the entries the
 * menu can offer. `grid_tools.js` moves it to the cursor on a
 * `contextmenu` event, fills the header with the current row's key
 * fields, and points every entry at a URL derived from the base URLs
 * carried on the div - the JS never fabricates paths from string glue.
 *
 * Only entries the current user can act on are rendered. A visitor with
 * read-only rights sees "view" / "copy cell" / "filter", never a
 * delete button that would fail with a 403 the second it is clicked.
 *
 * "Delete" here calls `DELETE /admin/webapi/data/{table}/{id}` after a
 * confirm - the same endpoint the batch delete uses, so the workflow,
 * revision and observers stay in the same code path. It is NOT
 * separate from the batch action; it is the single-row shortcut to it.
 */
class GridContextMenu extends AbstractTool
{
    /** @var CustomTable */
    protected $custom_table;

    /**
     * @param CustomTable $custom_table
     */
    public function __construct(CustomTable $custom_table)
    {
        $this->custom_table = $custom_table;
    }

    /**
     * The column "assign this to me" would write into.
     *
     * The first single-value user column of the table, in the order the
     * table defines its columns - on an incident that is the assignee,
     * on a request the owner. A multi-value user column is skipped: a
     * one-click action cannot decide whether the intent was to replace
     * the list or to join it, and guessing wrong silently drops the
     * people already on it.
     *
     * Returns null when the table has no such column, and the menu entry
     * is then not rendered at all rather than shown and failing.
     */
    protected function findAssignColumn(): ?CustomColumn
    {
        foreach ($this->custom_table->custom_columns_cache as $custom_column) {
            if ($custom_column->column_type != ColumnType::USER) {
                continue;
            }
            if (boolval(array_get($custom_column, 'options.multiple_enabled'))) {
                continue;
            }
            return $custom_column;
        }

        return null;
    }

    /**
     * @return string
     */
    public function render()
    {
        $canAccess = $this->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE);
        $canEdit = $this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
        // enableCreate() returns true or an ErrorCode - a copy is a create.
        $canCopy = $this->custom_table->enableCreate(true) === true;

        // No entry at all is worth showing - skip the wrapper too so the
        // JS has nothing to bind to.
        if (!$canAccess && !$canEdit && !$canCopy) {
            return '';
        }

        $gridId = e($this->grid->tableID);
        $tableName = $this->custom_table->table_name;
        $listBaseUrl = e(admin_urls('data', $tableName));
        $viewUrlBase = e(admin_urls('data', $tableName));
        $webapiBase = e(admin_urls('webapi', 'data', $tableName));
        // Same endpoint the inline editor re-reads a cell from. Carried
        // here as well so "assign to me" can repaint the cell it changed
        // even on a grid where inline editing is switched off.
        $cellBase = e(admin_urls('webapi', 'data', $tableName, 'cell'));
        $csrf = e(csrf_token());

        // "Assign to me" needs both a column to write into and a user to
        // write - an unauthenticated render (a public form preview) has
        // no user, and not every table has an assignee.
        $assignColumn = $canEdit ? $this->findAssignColumn() : null;
        $assignUser = \Exment::user() ? \Exment::user()->base_user_id : null;
        $assignName = $assignColumn ? e($assignColumn->column_name) : '';
        $assignUserId = $assignUser ? e(strval($assignUser)) : '';

        $labels = [
            'view' => e(exmtrans('common.grid_ctx_view')),
            'preview' => e(exmtrans('common.grid_ctx_preview')),
            'assign' => $assignColumn
                ? e(exmtrans('common.grid_ctx_assign', ['column' => $assignColumn->column_view_name]))
                : '',
            // Strings the peek modal and the assign action need. They ride
            // on this div for the same reason the filter chip's do:
            // grid_tools.js is a static file with no translations.
            'peek_loading' => e(exmtrans('common.grid_peek_loading')),
            'peek_error' => e(exmtrans('common.grid_peek_error')),
            'peek_open' => e(exmtrans('common.grid_peek_open')),
            'peek_close' => e(exmtrans('common.grid_peek_close')),
            'assign_done' => $assignColumn
                ? e(exmtrans('common.grid_ctx_assign_done', ['column' => $assignColumn->column_view_name]))
                : '',
            'assign_error' => e(exmtrans('common.grid_ctx_assign_error')),
            'edit' => e(exmtrans('common.grid_ctx_edit')),
            'copy' => e(exmtrans('common.grid_ctx_copy')),
            'filter' => e(exmtrans('common.grid_ctx_filter')),
            'copy_cell' => e(exmtrans('common.grid_ctx_copy_cell')),
            'delete' => e(exmtrans('common.grid_ctx_delete')),
            'confirm' => e(exmtrans('common.grid_ctx_delete_confirm')),
            // Strings for the "filtering this page" chip grid_tools.js
            // raises above the table. They ride on this div because the JS
            // is a static file with no access to the translations, and
            // because the chip belongs to the same feature as the menu
            // entry that switches it on.
            'filter_active' => e(exmtrans('common.grid_filter_active')),
            'filter_hidden' => e(exmtrans('common.grid_filter_hidden')),
            'filter_clear' => e(exmtrans('common.grid_filter_clear')),
        ];

        $items = '';
        if ($canAccess) {
            // Peek first: it is the cheapest of the three - it does not
            // leave the list, so nothing is lost if it was the wrong row.
            $items .= <<<HTML
<a href="#" data-act="preview"><i class="fa fa-window-restore"></i>&nbsp;{$labels['preview']}</a>
<a href="#" data-act="view"><i class="fa fa-eye"></i>&nbsp;{$labels['view']}</a>
HTML;
        }
        if ($canEdit) {
            $items .= <<<HTML
<a href="#" data-act="edit"><i class="fa fa-edit"></i>&nbsp;{$labels['edit']}</a>
HTML;
        }
        if ($canCopy) {
            $items .= <<<HTML
<a href="#" data-act="copy-row"><i class="fa fa-copy"></i>&nbsp;{$labels['copy']}</a>
HTML;
        }

        if ($assignColumn && $assignUserId !== '') {
            $items .= <<<HTML
<a href="#" data-act="assign-me"><i class="fa fa-user-check"></i>&nbsp;{$labels['assign']}</a>
HTML;
        }

        $sep1 = ($canAccess || $canEdit || $canCopy) ? '<div class="exm-ctx-sep"></div>' : '';
        $items .= $sep1;

        // Filter and copy-cell are read-only shortcuts. Anyone who can
        // reach the grid at all can use them - no extra permission.
        //
        // Filtering happens in the browser, over the rows of the page in
        // front of the user, which is why the label says so. A server side
        // filter is not reachable from here: the grid only accepts a query
        // parameter for a column the view lists in `custom_view_grid_filters`,
        // and a view that has none - the default - registers no custom
        // column at all, so no URL could carry the condition.
        $items .= <<<HTML
<a href="#" data-act="filter" class="exm-ctx-filter"><i class="fa fa-filter"></i>&nbsp;<span class="exm-ctx-filter-label">{$labels['filter']}</span></a>
<a href="#" data-act="copy-cell"><i class="fa fa-clipboard"></i>&nbsp;{$labels['copy_cell']}</a>
HTML;

        if ($canEdit) {
            $items .= <<<HTML
<div class="exm-ctx-sep"></div>
<a href="#" data-act="delete" class="exm-ctx-danger"><i class="fa fa-trash"></i>&nbsp;{$labels['delete']}</a>
HTML;
        }

        return <<<HTML
<div class="exm-ctxmenu" data-grid="{$gridId}"
    data-list-url="{$listBaseUrl}"
    data-view-url="{$viewUrlBase}"
    data-webapi-url="{$webapiBase}"
    data-cell-url="{$cellBase}"
    data-assign-col="{$assignName}"
    data-assign-user="{$assignUserId}"
    data-assign-done="{$labels['assign_done']}"
    data-assign-error="{$labels['assign_error']}"
    data-peek-loading="{$labels['peek_loading']}"
    data-peek-error="{$labels['peek_error']}"
    data-peek-open="{$labels['peek_open']}"
    data-peek-close="{$labels['peek_close']}"
    data-csrf="{$csrf}"
    data-confirm="{$labels['confirm']}"
    data-filter-label="{$labels['filter']}"
    data-filter-active="{$labels['filter_active']}"
    data-filter-hidden="{$labels['filter_hidden']}"
    data-filter-clear="{$labels['filter_clear']}">
    <div class="exm-ctx-head"></div>
    {$items}
</div>
HTML;
    }
}
