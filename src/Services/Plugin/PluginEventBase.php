<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (Event) base class
 */
class PluginEventBase
{
    use PluginBase;
    use PluginEventTrait;

    public $custom_table;
    public $custom_value;
    public $isCreate;
    public $isDelete;
    public $isForceDelete;

    public function __construct($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->_initEvent($plugin, $custom_table, $custom_value, $options);
        $this->pluginOptions = new PluginOption\PluginOptionEvent($options);
    }

    public function execute()
    {
    }
}
