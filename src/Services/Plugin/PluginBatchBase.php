<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (batch) base class
 */
class PluginBatchBase
{
    use PluginBase;

    public function __construct($plugin, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginOptions = new PluginOption\PluginOptionBatch($options);
    }

    /**
     * Processing during batch execution.
     *
     * @return void
     */
    public function execute()
    {
    }
}
