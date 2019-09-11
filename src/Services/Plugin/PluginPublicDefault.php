<?php

/**
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginPublicDefault extends PluginPublicBase
{
    use PluginBase;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
}
