<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Tools\AbstractTool;
use Illuminate\Contracts\Support\Htmlable;

class PluginMenuButton
{
    protected $plugin;
    protected $custom_table;
    protected $id;
    
    public function __construct($plugin, $custom_table, $id = null){
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->id = $id;
    }

    protected function script()
    {
        $uuid = array_get($this->plugin, 'uuid');
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if(isset($this->id)){
            $url = admin_base_path(url_join("data", $table_name, $this->id, "pluginClick"));
        }else{
            $url = admin_base_path(url_join("data", $table_name, "pluginClick"));
        }
        return <<<EOT
        $('#plugin_menu_button_$uuid').off('click').on('click', function(){
            $.pjax({
                type: "POST",
                url: "$url",
                container: "#pjax-container",
                data:{ _pjax: true, _token: LA.token,uuid:"$uuid"},
                success:function(reponse) {
                    toastr.success(reponse);
                }
            });
        });
EOT;
    }

    public function render()
    {
        Admin::script($this->script());

        // get button_class
        $button_class = array_get($this->plugin, 'button_class');
        if(!isset($button_class)){
            $button_class = 'btn-default';
        }

        return view('exment::tools.plugin-menu-button', ['plugin' => $this->plugin, 'button_class' => $button_class]);
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
