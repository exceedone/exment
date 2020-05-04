<?php
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\CustomValue;

/**
 * Plugin (Event) base class
 */
class PluginEventBase
{
    use PluginBase, PluginEventTrait;
    
    public $custom_table;
    public $custom_value;
    public $isCreate;

    public function __construct($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->_initEvent($plugin, $custom_table, $custom_value, $options);
    }

    public function execute()
    {
    }
}
