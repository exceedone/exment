<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Grid\Tools\ColumnSelector;

/**
 * "Keep these columns visible while scrolling" toolbar button.
 *
 * laravel-admin ships Grid\Tools\FixColumns for the same purpose, but it
 * renders the grid three times (left block / scrollable middle / right
 * block) and swaps in its own blade. That triples every row, and Exment
 * hangs a lot on a row: the batch checkbox, the action links, the cell
 * appearance markup, the pjax reload. Splitting the table would have to
 * keep all of it in sync across three copies.
 *
 * So nothing is duplicated here. The grid stays one table and the JS in
 * grid_tools.js only adds a class plus a `left` offset to the cells of
 * the chosen columns - `position: sticky` does the rest. The parent
 * `.box-body.table-responsive` already provides the horizontal overflow
 * sticky needs, so the table markup is untouched.
 *
 * Only `getGridColumns()` is inherited from ColumnSelector: it returns
 * exactly the `[grid column name => label]` pairs the table renders as
 * `column-<name>` on both th and td, which is what the JS matches on.
 * The parent's render() is never reached.
 */
class GridColumnPin extends ColumnSelector
{
    /**
     * "Pin the first N columns" shortcuts. Each needs a matching
     * `common.grid_pin_preset_{n}` translation key.
     *
     * @var array<int, int>
     */
    public const PRESETS = [2, 3];

    /**
     * Key the pinned set is stored under, per table: a column list only
     * means something for the table it came from.
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
        // One column cannot be pinned against anything - there is nothing
        // left to scroll underneath it.
        if ($columns->count() < 2) {
            return '';
        }

        $gridId = e($this->grid->tableID);
        $key = e($this->storageKey);
        $title = e(exmtrans('common.grid_pin'));
        // Tooltip of the marker the JS puts on a pinned header. Carried on
        // the element because grid_tools.js is a static file - it has no
        // way to reach the translations.
        $flag = e(exmtrans('common.grid_pin_flag'));
        // Toast shown when a narrow screen forces applyPins to leave some
        // of the chosen columns unpinned - same static-file reason.
        $narrow = e(exmtrans('common.grid_pin_narrow'));

        $items = '';
        foreach (static::PRESETS as $preset) {
            if ($columns->count() <= $preset) {
                continue;
            }
            $label = e(exmtrans('common.grid_pin_preset_' . $preset));
            $items .= <<<HTML
<li><a class="dropdown-item exm-pin-preset" href="#" data-preset="{$preset}">
    <i class="fa fa-thumbtack"></i><span>{$label}</span>
</a></li>
HTML;
        }

        $none = e(exmtrans('common.grid_pin_none'));
        $actions = e(exmtrans('common.grid_pin_actions'));
        // Header lock rides in the pin menu because it is the same idea
        // turned 90 degrees: pinning keeps columns on screen against the
        // horizontal scroll, this keeps the header row on screen against
        // the vertical one (grid_tools.js applyHeadLock).
        $head = e(exmtrans('common.grid_pin_head'));
        $items .= <<<HTML
<li><a class="dropdown-item exm-pin-preset" href="#" data-preset="0">
    <i class="fa fa-ban"></i><span>{$none}</span>
</a></li>
<li><a class="dropdown-item exm-pin-right" href="#">
    <i class="fa fa-thumbtack"></i><span>{$actions}</span>
</a></li>
<li><a class="dropdown-item exm-pin-head" href="#">
    <i class="fa fa-thumbtack"></i><span>{$head}</span>
</a></li>
<li><hr class="dropdown-divider"></li>
HTML;

        // Column order matters: the JS pins in the order the table renders,
        // not in the order the user ticked, so the list has to read the
        // same way the table does.
        foreach ($columns as $name => $label) {
            $name = e($name);
            $label = e($label);
            $items .= <<<HTML
<li><a class="dropdown-item exm-pin-item" href="#" data-col="{$name}">
    <i class="fa fa-thumbtack"></i><span>{$label}</span>
</a></li>
HTML;
        }

        return <<<HTML
<div class="btn-group exm-grid-tool exm-grid-pin" data-grid="{$gridId}" data-key="{$key}"
    data-flag="{$flag}" data-narrow="{$narrow}">
    <button type="button" class="btn btn-sm btn-default dropdown-toggle exm-pin-btn"
        data-bs-toggle="dropdown" aria-expanded="false" title="{$title}">
        <i class="fa fa-thumbtack"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow exm-pin-menu">
        <li><h6 class="dropdown-header">{$title}</h6></li>
        {$items}
    </ul>
</div>
HTML;
    }
}
