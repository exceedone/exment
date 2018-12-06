<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Tools\AbstractTool;

class GridChangePageMenu extends AbstractTool
{
    protected $page_name;
    protected $custom_table;
    protected $isselect_row;
    
    public function __construct($page_name, $custom_table, $isselect_row)
    {
        $this->page_name = $page_name;
        $this->custom_table = $custom_table;
        $this->isselect_row = $isselect_row;
    }

    protected function script()
    {
        $tables_json = CustomTable::filterList()->pluck('table_name', 'id')->toJson();
        $isselect_row_bool = boolval($this->isselect_row) ? 1 : 0;
        $custom_table_id = isset($this->custom_table) ? $this->custom_table->id : null;
        $custom_table_name = isset($this->custom_table) ? $this->custom_table->table_name : null;
        $error = exmtrans('common.error');
        $error_message = exmtrans('change_page_menu.error_select');
        
        return <<<EOT
        $('#custom-table-menu').find('li a').off('click').on('click',function(ev){
            var tables = $tables_json;
            var isselect = $isselect_row_bool;
            var table_id = '$custom_table_id';
            var table_name = '$custom_table_name';
            var uri = $(ev.target).data('url');
            var url = null;
            // get select row
            if(isselect){
                var rows = selectedRows();
                if(rows.length !== 1){
                    swal("$error", "$error_message", "error");
                    return;
                }
                else{
                    for(var key in tables){
                        if(key == rows[0]){
                            url = admin_base_path(URLJoin(uri, tables[key]));
                            break;
                        }
                    }
                }
            }else{
                if($(ev.target).data('edit') == 1){
                    url = admin_base_path(URLJoin(uri, table_id, 'edit'));
                }else{
                    url = admin_base_path(URLJoin(uri, table_name));
                }
            }
            if(url){
                $.pjax({ container: '#pjax-container', url: url });
            }
        });

EOT;
    }

    public function render()
    {
        $menulist = [];
        foreach (Define::GRID_CHANGE_PAGE_MENULIST as $menu) {
            // if same page, skip
            if ($this->page_name == array_get($menu, 'url')) {
                continue;
            }
            // check menu using authority
            // if this page_name is table and grid, check authority
            if ($this->page_name == 'table' && !isset($this->custom_table)) {
                // if user dont't has authority system
                if (!Admin::user()->hasPermission(array_get($menu, 'authorities'))) {
                    continue;
                }
            } else {
                // if user dont't has authority as table
                if (!Admin::user()->hasPermissionTable($this->custom_table, array_get($menu, 'authorities'))) {
                    continue;
                }
            }
            $menulist[] = $menu;
        }

        // if no menu, return
        if (count($menulist) == 0) {
            return null;
        }

        Admin::script($this->script());

        return view('exment::tools.menu-button', ['menulist' => $menulist]);
    }
}
