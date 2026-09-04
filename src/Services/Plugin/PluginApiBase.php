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

    // @phpstan-ignore-next-line
    public function _plugin()
    {
        return $this->plugin;
    }

    // @phpstan-ignore-next-line
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Get route uri for page
     *
     * @return string|null
     */
    // @phpstan-ignore-next-line
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
