<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;

/**
 * Row-height switch for the data grid.
 *
 * Purely presentational: the button only writes a `table-density-*`
 * class onto the grid table, every rule behind it lives in
 * grid_tools.css. Nothing is sent to the server, so the switch costs no
 * query and works on every view kind that renders the standard table.
 *
 * The chosen density is stored per user in localStorage (not per view):
 * it is a reading preference, and a user who wants compact rows wants
 * them on every table.
 */
class GridDensity extends AbstractTool
{
    public const DEFAULT_DENSITY = 'comfortable';

    /**
     * @return string
     */
    public function render()
    {
        $gridId = e($this->grid->tableID);
        $title = e(exmtrans('common.grid_density'));

        $items = '';
        foreach (['compact', 'comfortable', 'spacious'] as $density) {
            $label = e(exmtrans('common.grid_density_' . $density));
            $items .= <<<HTML
<li><a class="dropdown-item exm-density-item" href="#" data-density="{$density}">
    <i class="fa fa-check"></i><span>{$label}</span>
</a></li>
HTML;
        }

        // Row height and line wrapping are the same question asked twice -
        // "how tall is a row" - so the switch lives in this menu rather
        // than adding a seventh button to the toolbar. It is a toggle, not
        // a fourth density: the two settings combine.
        $nowrap = e(exmtrans('common.grid_nowrap'));
        $items .= <<<HTML
<li><hr class="dropdown-divider"></li>
<li><a class="dropdown-item exm-wrap-item" href="#">
    <i class="fa fa-check"></i><span>{$nowrap}</span>
</a></li>
HTML;

        return $this->buildHtml($gridId, $title, $items);
    }

    /**
     * Tag the table so grid_tools.css can reach it.
     *
     * The stock laravel-admin table blade renders
     * `<table class="table table-hover">` with no hook for extra classes,
     * so the marker has to be added client side.
     *
     * This is a standalone <script> tag on purpose. Admin::script() would
     * be the obvious home, but the admin::partials.script view packs
     * every registered snippet into a single `run()` function - one throw
     * anywhere earlier in that function and the class is never added, and
     * the whole grid silently loses its styling. An own tag cannot be
     * taken down by someone else's error.
     *
     * On the first render the table has not been parsed yet (the toolbar
     * comes before it), hence the readyState guard; after a pjax swap the
     * document is already complete and the table is in place, so the same
     * code runs immediately.
     */
    protected function markerScript(string $gridId): string
    {
        $default = static::DEFAULT_DENSITY;

        return <<<HTML
<script>
(function () {
    var apply = function () {
        var table = document.getElementById('{$gridId}');
        if (!table) { return; }
        var density = '{$default}';
        try {
            density = localStorage.getItem('exment_grid_density') || density;
        } catch (e) { /* storage blocked - keep the default */ }
        if (['compact', 'comfortable', 'spacious'].indexOf(density) === -1) {
            density = '{$default}';
        }
        table.classList.add('exm-grid', 'table-density-' + density);
        // Applied here too, so a one-line grid never paints its rows two
        // lines tall first and then snaps shut when grid_tools.js boots.
        var nowrap = '1';
        try {
            nowrap = localStorage.getItem('exment_grid_nowrap') || '1';
        } catch (e) { /* storage blocked - keep the default */ }
        if (nowrap !== '0') { table.classList.add('exm-nowrap'); }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
</script>
HTML;
    }

    /**
     * @return string
     */
    protected function buildHtml(string $gridId, string $title, string $items): string
    {
        $marker = $this->markerScript($gridId);

        return <<<HTML
{$marker}
<div class="btn-group exm-grid-tool exm-grid-density" data-grid="{$gridId}">
    <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown"
        aria-expanded="false" title="{$title}">
        <i class="fa fa-bars"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><h6 class="dropdown-header">{$title}</h6></li>
        {$items}
    </ul>
</div>
HTML;
    }
}
