<?php
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\CustomValue;

/**
 * Plugin (Trigger) base class
 */
class PluginTriggerBase
{
    use PluginBase;
    
    public $custom_table;
    public $custom_value;
    //public $custom_form;
    //public $custom_column;
    public $isCreate;

    // workflow action(if call as workflow)
    public $workflow_action;

    // notify(if call as notify)
    public $notify;

    public function __construct($plugin, $custom_table, $custom_value, $options = [])
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

    public function execute()
    {
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
}
