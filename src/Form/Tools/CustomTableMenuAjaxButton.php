<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\CustomTable;

/**
 * Custom Table Menu. as ajax. Only Custom table list
 */
class CustomTableMenuAjaxButton extends ModalTileAjaxMenuButton
{
    use CustomTableMenuTrait;

    public function __construct()
    {
        $this->page_name = 'table';
        $this->page_name_sub = null;

        parent::__construct(
            null,
            [
            'label' => exmtrans("change_page_menu.change_page_label"),
            'icon' => 'fa-cog',
            'button_class' => 'btn-default',
        ]
        );
        $this->modal_title = exmtrans("change_page_menu.change_page_label");
    }

    public function id($id)
    {
        $this->custom_table = CustomTable::getEloquent($id);
    }

    protected function script()
    {
        $uuid = $this->uuid;

        return <<<EOT

        $('.block_custom_table').find('.grid-row-checkbox').off('ifChanged').on('ifChanged',function(ev){
            var rows = selectedRows();
            $('[data-widgetmodal_uuid="$uuid"]').attr('disabled', rows.length !== 1);
        });

        $('[data-widgetmodal_uuid="$uuid"]').off('click').on('click', function(ev){
            var rows = selectedRows();
            if(rows.length !== 1){
                return;
            }

            url = admin_url(URLJoin('table', 'menuModal', rows[0]));
            Exment.ModalEvent.ShowModal($(ev.target), url);
        });
EOT;
    }

    /**
     * @return string|null
     */
    public function ajaxHtml()
    {
        $items = $this->getItems();

        // if no menu, return
        if (count($items) == 0) {
            return null;
        }

        $this->groups = [[
            'items' => $items
        ]];

        return parent::html();
    }

    public function render()
    {
        \Admin::script($this->script());

        $this->attributes['disabled'] = true;

        return get_parent_class(get_parent_class(get_parent_class($this)))::render();
    }
}
