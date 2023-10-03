<?php

/**
 * Execute Batch
 */

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Controllers\ApiTrait;

/**
 * Plugin (API) base class
 */
class PluginApiBase
{
    use ApiTrait;
    use PluginBase;

    public function _plugin()
    {
        return $this->plugin;
    }

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Get route uri for page
     *
     * @return string|null
     */
    public function getRouteUri($endpoint = null)
    {
        if (!isset($this->plugin)) {
            return null;
        }

        return $this->plugin->getRouteUri($endpoint);
    }

    /**
     * override method.
     *
     * @return null
     */
    public function _getLoadView()
    {
        return null;
    }
}
