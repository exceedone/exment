<?php

/**
 */

namespace Exceedone\Exment\Services\Plugin;

/**
 * Instantiated when no special processing is prepared in the plugin(Style, Script)
 */
class PluginPublicDefault extends PluginPublicBase
{
    use PluginBase;

    // @phpstan-ignore-next-line
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
}
