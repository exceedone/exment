<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (Trigger) base class
 */
class PluginTriggerBase
{
    use PluginBase;
    use PluginEventTrait;
    use PluginButtonTrait;

    // @phpstan-ignore-next-line
    public $custom_table;
    // @phpstan-ignore-next-line
    public $custom_value;
    // @phpstan-ignore-next-line
    public $isCreate;

    // @phpstan-ignore-next-line
    public function __construct($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->_initButton($plugin, $custom_table, $custom_value, $options);
        $this->_initEvent($plugin, $custom_table, $custom_value, $options);
    }

    // @phpstan-ignore-next-line
    public function execute()
    {
    }
}
