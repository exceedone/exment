<?php

/**
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginStyleDefault extends PluginPublicBase
{
    use PluginBase;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
}
