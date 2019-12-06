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
    public $workflow_action;

    public function __construct($plugin, $custom_table, $custom_value, $workflow_action)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        
        if ($custom_value instanceof CustomValue) {
            $this->custom_value = $custom_value;
        } elseif (isset($custom_value) && isset($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value);
        }

        if (isset($workflow_action)) {
            $this->$workflow_action = $workflow_action;
        }
        $this->isCreate = !isset($custom_value);
    }

    public function execute()
    {
    }
}
