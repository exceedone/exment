<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\Adapter\Local;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;

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
    public function getPluginFullPath($plugin, ...$pass_array)
    {
        $pluginDir = \Storage::disk(Define::DISKNAME_PLUGIN_LOCAL)->getAdapter()->applyPathPrefix($plugin->getPath());
        if (!\File::exists($pluginDir)) {
            \File::makeDirectory($pluginDir, 0775, true);
        }
        $plugin->requirePlugin($pluginDir);

        return path_join($pluginDir, ...$pass_array);
    }

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config)
    {
        return new self(array_get($config, 'root'));
    }
}
