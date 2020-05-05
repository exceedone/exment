<?php
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\CustomValue;

/**
 * Plugin (Event) trait
 */
trait PluginEventTrait
{
    // workflow action(if call as workflow)
    public $workflow_action;

    // notify(if call as notify)
    public $notify;

    /**
     * Init event
     *
     * @param [type] $plugin
     * @param [type] $custom_table
     * @param [type] $custom_value
     * @param array $options
     * @return void
     */
    protected function _initEvent($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        
        if ($custom_value instanceof CustomValue) {
            $this->custom_value = $custom_value;
        } elseif (isset($custom_value) && isset($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value);
        }

        if (isset($options['workflow_action'])) {
            $this->workflow_action = $options['workflow_action'];
        }
        if (isset($options['notify'])) {
            $this->notify = $options['notify'];
        }
        $this->isCreate = !isset($custom_value);
    }
}
