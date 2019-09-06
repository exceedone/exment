<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Enums\PluginPageType;

class PluginPageBase extends PluginPublicBase
{
    use PluginPageTrait;

    protected $showHeader = true;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * whether showing content header
     *
     * @return void
     */
    public function _showHeader()
    {
        return $this->showHeader;
    }
}
