<?php

/**
 * Execute Batch
 */

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (Page) base class
 */
class PluginPageBase extends PluginPublicBase
{
    use PluginBase;
    use PluginPageTrait;

    protected $showHeader = true;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * whether showing content header
     *
     * @return bool|mixed
     */
    public function _showHeader()
    {
        return $this->showHeader;
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
}
