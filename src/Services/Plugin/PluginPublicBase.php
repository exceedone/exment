<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin Public file trait
 */
class PluginPublicBase
{
    protected $plugin;
    
    public function _plugin(){
        return $this->plugin;
    }

    /**
     * get css files
     *
     * @return array
     */
    public function css($skipPath = false){
        return $this->getCssJsFiles($skipPath ? null : 'css', 'css');
    }

    /**
     * get js path
     *
     * @return void
     */
    public function js($skipPath = false){
        return $this->getCssJsFiles($skipPath ? null : 'js', 'js');
    }

    /**
     * get public path
     *
     * @return void
     */
    protected function getCssJsFiles($path, $type){
        $base_path = $this->plugin->getFullPath('public');
        $type_path = path_join($base_path, $path);
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
}
