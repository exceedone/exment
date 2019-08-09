<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginPageBase
{
    use PluginBase;

    protected $showHeader = true;
    
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function _plugin(){
        return $this->plugin;
    }

    /**
     * get css files
     *
     * @return array
     */
    public function css(){
        return $this->getCssJsFiles('css');
    }

    /**
     * get js path
     *
     * @return void
     */
    public function js(){
        return $this->getCssJsFiles('js');
    }

    /**
     * whether showing content header
     *
     * @return void
     */
    public function _showHeader(){
        return $this->showHeader;
    }

    protected function getCssJsFiles($type){
        $base_path = $this->plugin->getFullPath('public');
        $type_path = \path_join($base_path, $type);
        if(!\File::exists($type_path)){
            return [];
        }

        // get files
        $files = \File::allFiles($type_path);

        return collect($files)->filter(function($file) use($type){
            return pathinfo($file)['extension'] == $type;
        })->map(function($file) use($base_path){
            $path = trim(str_replace($base_path, '', $file->getPathName()), '/');
            return str_replace('\\', '/', $path);
        })->toArray();
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
