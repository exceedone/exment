<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Grid\Tools\ColumnSelector;

/**
 * "Select columns to show" toolbar button.
 *
 * laravel-admin already ships the whole hide-column mechanism: the grid
 * reads the `_columns_` query parameter and builds visibleColumns() /
 * visibleColumnNames() from it, and the paginator appends the parameter
 * to every page / sort link. What Exment switches off globally
 * (Middleware\Initialize::registeredLaravelAdmin) is only the stock
 * *button*, an unlabelled dropdown that is unusable once a table has a
 * few dozen columns.
 *
 * So this tool re-exposes the feature instead of reimplementing it: a
 * searchable modal whose checkbox values are the very grid column names
 * (`ckey_...`) the filter expects. The click handling lives in
 * grid_tools.js rather than in an inline script, so a pjax reload does
 * not need to re-run anything.
 *
 * The parent's own render() is deliberately not called - it would return
 * an empty string because show_column_selector stays disabled, which is
 * what keeps the stock button from appearing next to this one.
 */
class GridColumnVisibility extends ColumnSelector
{
    /**
     * @param Grid $grid
     */
    public function __construct(Grid $grid)
    {
        parent::__construct($grid);
    }

    /**
     * @return string
     */
    public function render()
    {
        $columns = $this->getGridColumns();
        if ($columns->isEmpty()) {
            return '';
        }

        // Columns shown right now, and the set that counts as "default"
        // (every column of the view). Comparing the two is how the Apply
        // handler decides whether it can drop the query parameter.
        $shown = $this->grid->visibleColumnNames();
        $defaults = $this->grid->getDefaultVisibleColumnNames();

        $modalId = $this->grid->tableID . '-columns';

        $items = $columns->map(function ($label, $name) use ($shown, $defaults) {
            $checked = (empty($shown) || in_array($name, $shown)) ? ' checked' : '';
            $isDefault = in_array($name, $defaults) ? '1' : '0';
            $label = e($label);
            $name = e($name);

            return <<<HTML
<li class="exm-col-item">
    <label>
        <input type="checkbox" class="exm-col-check" value="{$name}" data-default="{$isDefault}"{$checked}>
        <span class="exm-col-name">{$label}</span>
    </label>
</li>
HTML;
        })->implode("\n");

        $t = [
            'title' => e(exmtrans('common.grid_column_visibility')),
            'search' => e(exmtrans('common.grid_column_search')),
            'all' => e(exmtrans('common.grid_column_all')),
            'none' => e(exmtrans('common.grid_column_none')),
            'default' => e(exmtrans('common.grid_column_default')),
            'apply' => e(exmtrans('common.grid_column_apply')),
            'empty' => e(exmtrans('common.grid_column_empty')),
            'cancel' => e(trans('admin.cancel')),
        ];
        $gridId = e($this->grid->tableID);

        return <<<HTML
<div class="btn-group exm-grid-tool" data-grid="{$gridId}">
    <button type="button" class="btn btn-sm btn-default" data-bs-toggle="modal"
        data-bs-target="#{$modalId}" title="{$t['title']}">
        <i class="fa fa-columns"></i>
    </button>
</div>
<div class="modal fade exm-col-modal" id="{$modalId}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title"><i class="fa fa-columns"></i>&nbsp;{$t['title']}</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control input-sm exm-col-search" placeholder="{$t['search']}">
                <div class="exm-col-actions">
                    <a href="#" class="exm-col-all">{$t['all']}</a>
                    <a href="#" class="exm-col-none">{$t['none']}</a>
                    <a href="#" class="exm-col-default">{$t['default']}</a>
                </div>
                <ul class="exm-col-list">
                    {$items}
                </ul>
            </div>
            <div class="modal-footer">
                <span class="exm-col-warn">{$t['empty']}</span>
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">{$t['cancel']}</button>
                <button type="button" class="btn btn-primary exm-col-apply" data-grid="{$gridId}">
                    <i class="fa fa-check"></i>&nbsp;{$t['apply']}
                </button>
            </div>
        </div>
    </div>
</div>
HTML;
    }
}
