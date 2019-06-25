<?php
/**
 * Created by PhpStorm.
 * User: ChienSV
 * Date: 6/8/2018
 * Time: 4:56 PM
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginTriggerBase
{
    use PluginBase;
    
    public $custom_table;
    public $custom_value;
    public $custom_form;
    public $custom_column;
    public $isCreate;

    public function __construct($plugin, $custom_table, $custom_value_id)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        if (isset($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value_id);
        }
    }

    public function execute()
    {
    }
}
