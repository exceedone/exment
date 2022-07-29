<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * PluginSettingBase.
 * Please extends if plugin_type is multiple, and want to add custom setting.
 */
abstract class PluginSettingBase
{
    use PluginBase;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
}
