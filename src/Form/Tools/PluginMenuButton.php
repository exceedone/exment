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

    protected function script($uuid, $label)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_base_paths("data", $table_name, $this->id, "pluginClick");
        } else {
            $url = admin_base_paths("data", $table_name, "pluginClick");
        }
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = esc_html(sprintf(exmtrans('common.message.confirm_execute'), ($label ?? exmtrans('common.plugin'))));

        return <<<EOT

        $('#menu_button_$uuid').off('click').on('click', function(){
            Exment.CommonEvent.ShowSwal("$url", {
                title: "$label",
                confirm:"$confirm",
                cancel:"$cancel",
                data: {
                    uuid:"$uuid"
                }
            });
        });
EOT;
    }

    public function render()
    {
        // get label
        if (!is_null(array_get($this->plugin, 'options.label'))) {
            $label = array_get($this->plugin, 'options.label');
        } elseif (isset($this->plugin->plugin_view_name)) {
            $label = $this->plugin->plugin_view_name;
        }

        // get uuid
        $uuid = array_get($this->plugin, 'uuid');
        Admin::script($this->script($uuid, $label));

        // get button_class
        $button_class = array_get($this->plugin, 'options.button_class');
        if (!isset($button_class)) {
            $button_class = 'btn-default';
        }

        return view('exment::tools.plugin-menu-button', [
            'uuid' => $uuid,
            'label' => $label ?? null,
            'button_class' => $button_class,
            'icon' => array_get($this->plugin, 'options.icon') ?? '',
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
