<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;
use Exceedone\Exment\Enums\Permission;
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
        $csrf = e(csrf_token());

        $labels = [
            'view' => e(exmtrans('common.grid_ctx_view')),
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
            $items .= <<<HTML
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
