<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;

/**
 * On/off switch for the Excel-like keyboard mode of the data grid.
 *
 * Off (the default) the grid behaves as it always has: a click on a row
 * opens the record, a double click or the pen edits a cell. On, a click
 * selects a cell instead, the arrow keys move that selection and F2,
 * Enter or simply typing opens the inline editor on it.
 *
 * The button is all this class renders; the behaviour is grid_tools.js.
 * It only changes how an edit is started and where the focus goes - the
 * edit itself is GridInlineEditor's, with the same save path and the same
 * rules, so there is nothing here to check a permission for. A grid
 * without a single editable column still gets the button: moving through
 * the cells with the keyboard works on read-only cells too.
 *
 * The choice is kept in localStorage, one setting for the whole browser
 * (not per table, not on the server): it is a way of working, and whoever
 * works this way wants it on every table.
 */
class GridKeyboard extends AbstractTool
{
    /**
     * @return string
     */
    public function render()
    {
        $gridId = e($this->grid->tableID);
        // Name and keys in one line: the title is the only place a mouse
        // user can learn what the mode does before turning it on.
        $title = e(exmtrans('common.grid_keyboard') . ' (' . exmtrans('common.grid_keyboard_hint') . ')');
        // A short visible label: the keyboard icon alone was not recognized as a button.
        $label = e(exmtrans('common.grid_keyboard_label'));

        // aria-pressed starts false and grid_tools.js sets it from the
        // stored choice, together with the `active` class.
        return <<<HTML
<div class="btn-group exm-grid-tool exm-grid-kbd" data-grid="{$gridId}">
    <button type="button" class="btn btn-sm btn-default exm-kbd-toggle" aria-pressed="false"
        title="{$title}" aria-label="{$title}">
        <i class="fa fa-keyboard-o"></i><span class="exm-kbd-label">{$label}</span>
    </button>
</div>
HTML;
    }
}
