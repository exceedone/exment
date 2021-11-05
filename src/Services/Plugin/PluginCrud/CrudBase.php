<?php
namespace Exceedone\Exment\Services\Plugin\PluginCrud;

/**
 */
abstract class CrudBase
{
    public function __construct($plugin, $pluginClass, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginClass = $pluginClass;
    }

    protected $plugin;
    protected $pluginClass;
    

    /**
     * Get full url
     *
     * @return string
     */
    public function getFullUrl(...$endpoint) : string
    {
        array_unshift($endpoint, $this->pluginClass->getEndpoint());
        return $this->plugin->getFullUrl(...$endpoint);
    }
}
