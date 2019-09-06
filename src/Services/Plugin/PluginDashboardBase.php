<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Enums\PluginPageType;

class PluginDashboardBase extends PluginPublicBase
{
    use PluginPageTrait;
    
    protected $dashboard;

    public function __construct($plugin, $dashboard_box)
    {
        $this->plugin = $plugin;
        $this->dashboard_box = $dashboard_box;
    }

    /**
     * Get Dashboard Box Header html
     *
     * @return string
     */
    public function header(){
        return null;
    }
    
    /**
     * Get Dashboard Box body html
     *
     * @return string
     */
    public function body(){
        return null;
    }

    /**
     * Get Dashboard Box footer html
     *
     * @return string
     */
    public function footer(){
        return null;
    }
}
