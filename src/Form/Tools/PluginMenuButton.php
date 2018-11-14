<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * Plugin menu button.
 */
class PluginMenuButton
{
    protected $plugin;
    protected $custom_table;
    protected $id;
    
    public function __construct($plugin, $custom_table, $id = null)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->id = $id;
    }

    protected function script($uuid)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_base_path(url_join("data", $table_name, $this->id, "pluginClick"));
        } else {
            $url = admin_base_path(url_join("data", $table_name, "pluginClick"));
        }
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        // TODO:下のメッセージは要変更
        return <<<EOT

        $('#menu_button_$uuid').off('click').on('click', function(){
            swal({
                title: "プラグインを実行します。よろしいですか？",
                type: "warning",
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: "$confirm",
                showLoaderOnConfirm: true,
                cancelButtonText: "$cancel",
                preConfirm: function() {
                    return new Promise(function(resolve) {
                        $.ajax({
                            type: "POST",
                            url: "$url",
                            //container: "#pjax-container",
                            data:{ _pjax: true, _token: LA.token,uuid:"$uuid"},
                            success:function(repsonse) {
                                Exment.CommonEvent.CallbackExmentAjax(repsonse);
                                resolve(repsonse);
                            },
                            error: function(repsonse){
                                toastr.error(repsonse.message);
                            }
                        });
                    });
                }
            }).then(function(data) {
                var value = data.value;
                if (value.result) {
                    swal(value.message, '', 'success');
                } else {
                    swal(value.message, '', 'error');
                }
            });
    
        });
EOT;
    }

    public function render()
    {
        // get uuid
        $uuid = array_get($this->plugin, 'uuid');
        Admin::script($this->script($uuid));

        // get button_class
        $button_class = array_get($this->plugin, 'button_class');
        if (!isset($button_class)) {
            $button_class = 'btn-default';
        }

        // get label
        if (!is_null(array_get($this->plugin, 'options.label'))) {
            $label = array_get($this->plugin, 'options.label');
        } elseif (isset($this->plugin->plugin_view_name)) {
            $label = $this->plugin->plugin_view_name;
        }

        return view('exment::tools.plugin-menu-button', [
            'uuid' => $uuid,
            'label' => $label ?? null,
            'button_class' => $button_class
        ]);
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
