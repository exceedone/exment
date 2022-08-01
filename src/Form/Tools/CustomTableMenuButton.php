<?php

namespace Exceedone\Exment\Form\Tools;

/**
 * Custom Table Menu
 */
class CustomTableMenuButton extends ModalTileMenuButton
{
    use CustomTableMenuTrait;

    public function __construct($page_name, $custom_table, $page_name_sub = null)
    {
        $this->page_name = $page_name;
        $this->custom_table = $custom_table;
        $this->page_name_sub = $page_name_sub;

        parent::__construct([
            'label' => exmtrans("change_page_menu.change_page_label"),
            'icon' => 'fa-cog',
            'button_class' => 'btn-default',
        ]);
        $this->modal_title = exmtrans("change_page_menu.change_page_label");
    }

    public function render()
    {
        $items = $this->getItems();

        // if no menu or only 1 item, return
        if (count($items) <= 1) {
            return null;
        }

        $this->groups = [[
            'items' => $items
        ]];

        return parent::render();
    }
}
