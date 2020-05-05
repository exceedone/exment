<?php
namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (Button) base class
 */
class PluginButtonBase
{
    use PluginBase, PluginButtonTrait;
    
    public $custom_table;
    public $custom_value;
    public $isCreate;

    public function __construct($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->_initButton($plugin, $custom_table, $custom_value, $options);
    }

    public function execute()
    {
    }
}
