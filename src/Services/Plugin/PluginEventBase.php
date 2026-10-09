<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (Event) base class
 */
class PluginEventBase
{
    use PluginBase;
    use PluginEventTrait;

    // @phpstan-ignore-next-line
    public $custom_table;
    // @phpstan-ignore-next-line
    public $custom_value;
    // @phpstan-ignore-next-line
    public $isCreate;
    // @phpstan-ignore-next-line
    public $isDelete;
    // @phpstan-ignore-next-line
    public $isForceDelete;

    // @phpstan-ignore-next-line
    public function __construct($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->_initEvent($plugin, $custom_table, $custom_value, $options);
        $this->pluginOptions = new PluginOption\PluginOptionEvent($options);
    }

    // @phpstan-ignore-next-line
    public function execute()
    {
    }
}
