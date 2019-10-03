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

    public function __construct($plugin, $custom_table, $custom_value)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        
        if ($custom_value instanceof CustomValue) {
            $this->custom_value = $custom_value;
        } elseif (isset($custom_value) && isset($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value);
        }

        $this->isCreate = !isset($custom_value);
    }

    public function execute()
    {
    }
}
