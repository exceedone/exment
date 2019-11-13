<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (API) base class
 */
class PluginApiBase extends PluginPublicBase
{
    use PluginPageTrait;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
    
    /**
     * Get route uri for page
     *
     * @return void
     */
    public function getRouteUri($endpoint = null)
    {
        if (!isset($this->plugin)) {
            return null;
        }

        return $this->plugin->getRouteUri($endpoint);
    }
}
