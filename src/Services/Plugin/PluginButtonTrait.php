<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomValue;

/**
 * Plugin (Button) trait
 */
trait PluginButtonTrait
{
    /**
     * Init event
     *
     * @param Model\Plugin $plugin
     * @param Model\CustomTable|null $custom_table
     * @param Model\CustomValue|null $custom_value
     * @param array $options
     * @return void
     */
    protected function _initButton($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;

        if ($custom_value instanceof CustomValue) {
            $this->custom_value = $custom_value;
        } elseif (!is_nullorempty($custom_value) && !is_nullorempty($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value);
        }
    }

    public function getButtonLabel()
    {
        // get label
        if (!is_null(array_get($this->plugin, 'options.label'))) {
            return array_get($this->plugin, 'options.label');
        } elseif (isset($this->plugin->plugin_view_name)) {
            return $this->plugin->plugin_view_name;
        }
    }

    /**
     * Check if the button is displayed or not.
     *
     * @return boolean
     */
    public function enableRender()
    {
        return true;
    }
}
