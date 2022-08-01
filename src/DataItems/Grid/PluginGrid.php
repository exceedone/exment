<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Encore\Admin\Form;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomTable;
use Encore\Admin\Widgets\Box;

class PluginGrid extends GridBase
{
    protected $plugin;
    protected $pluginClass;

    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;

        // get plugin grid
        $plugin_id = $this->custom_view->getOption('plugin_id');

        $this->plugin = Plugin::getEloquent($plugin_id);

        $this->pluginClass = $this->plugin->getClass(PluginType::VIEW, [
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
        ]);
    }

    /**
     * Make a grid builder.
     *
     */
    public function grid()
    {
        $grid = $this->pluginClass->grid();

        if (!$this->pluginClass->useBox()) {
            return \Exment::getRender($grid);
        }

        $box = new Box($this->custom_view->view_view_name, \Exment::getRender($grid));
        foreach ($this->getBoxTools() as $tool) {
            $box->tools($tool);
        }

        return $box;
    }



    /**
     * getBoxTools
     *
     * @return array
     */
    protected function getBoxTools(): array
    {
        $tools = [];

        foreach ($this->pluginClass->useBoxButtons() as $buttonName) {
            switch ($buttonName) {
                case 'newButton':
                    $this->setNewButton($tools);
                    break;

                case 'menuButton':
                    $this->setTableMenuButton($tools);
                    break;

                case 'viewButton':
                    $this->setViewMenuButton($tools);
                    break;
            }
        }
        return $tools;
    }



    /**
     * Set custom view columns form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table, array $options = [])
    {
        $plugin_uuid = array_get($options, 'plugin');
        $plugin = Plugin::getPluginByUUID($plugin_uuid);
        if (is_nullorempty($plugin)) {
            return;
        }

        $pluginClass = $plugin->getClass(PluginType::VIEW, ['custom_table' => $custom_table]);
        if (is_nullorempty($pluginClass)) {
            return;
        }
        $pluginClass->setViewOptionForm($form);
    }
}
