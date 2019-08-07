<?php

namespace Exceedone\Exment\Adapter;

interface ExmentAdapterInterface
{
    /**
     * get adapter class
     */
    public static function getAdapter($app, $config);

    /**
     * Get Plugin Fullpath
     *
     * @return string
     */
    public function getPluginFullPath($plugin, ...$pass_array);
}
