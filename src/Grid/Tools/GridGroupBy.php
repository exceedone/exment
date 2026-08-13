<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Grid\Tools\ColumnSelector;

/**
 * "Group the rows by a column" toolbar button.
 *
 * Deliberately a client side grouping: the JS reorders the rows already
 * on the page and inserts a header row per distinct value. Nothing is
 * sent to the server, so it costs no query and cannot break a filter, a
 * sort or the paginator.
 *
 * That also fixes its ceiling, and the ceiling is the reason the label
 * says so out loud. Paging happens on the server, so a group counted
 * here counts the rows of the *current page* only - "in progress: 5" on
 * a 20-row page never means 5 in the whole table. Every menu entry
 * carries `common.grid_group_page` ("this page only") next to it so the
 * number is never read as a total. Real aggregation across the table is
 * what the summary view (SummaryGrid) is for.
 *
 * Grouping is keyed on the rendered cell text, not on the stored value:
 * the grid is the only thing the browser can see, and it is also what
 * the user is reading, so two rows group together exactly when they look
 * the same.
 */
class GridGroupBy extends ColumnSelector
{
    /**
     * Key the chosen column is stored under. sessionStorage, not local:
     * grouping reorders rows, and a reordering the user set up last week
     * should not be waiting for them tomorrow morning.
     *
     * @var string
     */
    protected $storageKey;

    /**
     * @param Grid $grid
     * @param string $storageKey
     */
    public function __construct(Grid $grid, string $storageKey)
    {
        parent::__construct($grid);

        $this->storageKey = $storageKey;
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

        $gridId = e($this->grid->tableID);
        $key = e($this->storageKey);
        $title = e(exmtrans('common.grid_group'));
        $page = e(exmtrans('common.grid_group_page'));
        $none = e(exmtrans('common.grid_group_none'));

        $items = <<<HTML
<li><a class="dropdown-item exm-group-item active" href="#" data-col="">
    <i class="fa fa-check"></i><span>{$none}</span>
</a></li>
<li><hr class="dropdown-divider"></li>
HTML;

        foreach ($columns as $name => $label) {
            $name = e($name);
            $label = e($label);
            $items .= <<<HTML
<li><a class="dropdown-item exm-group-item" href="#" data-col="{$name}">
    <i class="fa fa-check"></i><span>{$label}</span>
</a></li>
HTML;
        }

        return <<<HTML
<div class="btn-group exm-grid-tool exm-grid-group" data-grid="{$gridId}" data-key="{$key}"
    data-page-label="{$page}">
    <button type="button" class="btn btn-sm btn-default dropdown-toggle exm-group-btn"
        data-bs-toggle="dropdown" aria-expanded="false" title="{$title}">
        <i class="fa fa-layer-group"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow exm-group-menu">
        <li><h6 class="dropdown-header">{$title}<small>（{$page}）</small></h6></li>
        {$items}
    </ul>
</div>
HTML;
    }
}
