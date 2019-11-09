<?php

namespace Exceedone\Exment\Storage\Adapter;

interface ExmentAdapterInterface
{
    public function getUrl($path);

    /**
     * Get Plugin Fullpath
     *
     * @return string
     */
    public function getPluginFullPath($plugin, ...$pass_array);

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey);
}
