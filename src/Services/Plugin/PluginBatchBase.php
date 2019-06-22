<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginBatchBase
{
    use PluginBase;
    
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function execute()
    {
    }
}
