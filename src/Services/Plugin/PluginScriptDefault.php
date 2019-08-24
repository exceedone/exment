<?php

/**
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginScriptDefault extends PluginPublicBase
{
    use PluginBase;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
}
