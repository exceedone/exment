<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;

/**
 * Auto refresh timer for the data grid.
 *
 * Reloads the current URL through pjax on an interval, so the page the
 * user is on - including its filters, sort and page number - is what
 * gets refreshed. Meant for tables that are watched rather than edited
 * (incidents, orders in progress, an approval queue on a wall display).
 *
 * The interval is kept in sessionStorage, keyed by table, so it dies
 * with the browser tab: an auto refresh that a user forgot about should
 * not still be hammering the server tomorrow morning.
 */
class GridAutoRefresh extends AbstractTool
{
    /**
     * Intervals offered, in seconds. Each needs a matching
     * `common.grid_auto_refresh_{sec}` translation key.
     *
     * @var array<int, int>
     */
    public const INTERVALS = [30, 60, 300];

    /**
     * Key the chosen interval is stored under. Per table, so opening
     * another table does not inherit a timer the user set elsewhere.
     *
     * @var string
     */
    protected $storageKey;

    public function __construct(string $storageKey)
    {
        $this->storageKey = $storageKey;
    }

    /**
     * @return string
     */
    public function render()
    {
        $gridId = e($this->grid->tableID);
        $key = e($this->storageKey);
        $title = e(exmtrans('common.grid_auto_refresh'));
        $off = e(exmtrans('common.grid_auto_refresh_off'));

        $items = <<<HTML
<li><a class="dropdown-item exm-refresh-item" href="#" data-sec="0">
    <i class="fa fa-check"></i><span>{$off}</span>
</a></li>
HTML;
        foreach (static::INTERVALS as $sec) {
            $label = e(exmtrans('common.grid_auto_refresh_' . $sec));
            $items .= <<<HTML
<li><a class="dropdown-item exm-refresh-item" href="#" data-sec="{$sec}">
    <i class="fa fa-check"></i><span>{$label}</span>
</a></li>
HTML;
        }

        return <<<HTML
<div class="btn-group exm-grid-tool exm-grid-refresh" data-grid="{$gridId}" data-key="{$key}">
    <button type="button" class="btn btn-sm btn-default dropdown-toggle exm-refresh-btn"
        data-bs-toggle="dropdown" aria-expanded="false" title="{$title}">
        <i class="fa fa-refresh"></i><span class="exm-refresh-timer"></span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><h6 class="dropdown-header">{$title}</h6></li>
        {$items}
    </ul>
</div>
HTML;
    }
}
