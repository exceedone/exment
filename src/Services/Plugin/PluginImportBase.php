<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (Import) base class
 */
class PluginImportBase
{
    use PluginBase;

    // @phpstan-ignore-next-line
    protected $custom_table;

    // @phpstan-ignore-next-line
    protected $file;

    // @phpstan-ignore-next-line
    public function __construct($plugin, $custom_table, $file)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->file = $file;
    }

    // @phpstan-ignore-next-line
    public function execute()
    {
    }
}
