<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\Adapter\Local;

use Exceedone\Exment\Model\File;

class ExmentAdapterLocal extends Local implements ExmentAdapterInterface
{
    /**
     * Get URL using File class
     */
    public function getUrl($path)
    {
        return File::getUrl($path);
    }
    
    /**
     * get plugin full path 
     *
     * @return void
     */
    public function getPluginFullPath($plugin, ...$pass_array){
        $fullpath = base_path($plugin->getPath(...$pass_array));
        if (!\File::exists($fullpath)) {
            \File::makeDirectory($fullpath, 0775, true);
        }

        return $fullpath;
    }

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config)
    {
        return new self(array_get($config, 'root'));
    }
}
