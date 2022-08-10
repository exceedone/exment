<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\Plugin;

/**
 * Plugin menu button.
 */
class PluginMenuButton
{
    protected $plugin;
    protected $custom_table;
    protected $id;
    // set this plugin type
    protected $plugin_type;

    public function __construct($listButton, $custom_table, $id = null)
    {
        if ($listButton instanceof Plugin) {
            $this->plugin = $listButton;
            $this->plugin_type = collect($this->plugin->plugin_types)->first();
        } else {
            $this->plugin = array_get($listButton, 'plugin');
            $this->plugin_type = array_get($listButton, 'plugin_type');
        }
        $this->custom_table = $custom_table;
        $this->id = $id;
    }

    protected function script($uuid, $label)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_urls("data", $table_name, $this->id, "pluginClick");
        } else {
            $url = admin_urls("data", $table_name, "pluginClick");
        }
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = esc_html($label);
        $text = sprintf(exmtrans('common.message.confirm_execute'), ($label ?? exmtrans('common.plugin')));
        $plugin_type = $this->plugin_type;
        return <<<EOT

        $('#menu_button_$uuid').off('click').on('click', function(){
            let select_ids = $('.column-__row_selector__').length > 0 ? $.admin.grid.selected() : null;
            Exment.CommonEvent.ShowSwal("$url", {
                title: "$label",
                text: "$text",
                confirm:"$confirm",
                cancel:"$cancel",
                data: {
                    uuid:"$uuid",
                    plugin_type: '$plugin_type',
                    select_ids: select_ids
                }
            });
        });
EOT;
    }

    public function render()
    {
        // get label
        $pluginClass = $this->plugin->getClass($this->plugin_type, [
            'custom_table' => $this->custom_table,
            'id' => $this->id,
        ]);

        if (method_exists($pluginClass, 'enableRender') && !$pluginClass->enableRender()) {
            return null;
        }

        // if render method has and not null, return.
        $render = method_exists($pluginClass, 'render') ? $pluginClass->render() : null;
        if (!is_null($render)) {
            return $render;
        }

        if (method_exists($pluginClass, 'getButtonLabel')) {
            $label = $pluginClass->getButtonLabel();
        } else {
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
        $render = $this->render();
        return $render ? $render->render() : '';
    }
}
