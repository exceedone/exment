<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginImportBase
{
    use PluginBase;

    protected $file;
    
    public function __construct($plugin, $file)
    {
        $this->plugin = $plugin;
        $this->file = $file;
    }

    public function execute()
    {
    }
}
