<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginPageBase extends PluginPublicBase
{
    use PluginBase;

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
    public function _showHeader(){
        return $this->showHeader;
    }

    /**
     * get load view if view exists and path
     *
     * @return void
     */
    public function _getLoadView(){
        $base_path = $this->plugin->getFullPath(path_join('resources', 'views'));
        if(!\File::exists($base_path)){
            return null;
        }

        return [$base_path, 'exment_' . snake_case($this->plugin->plugin_name)];
    }
}
