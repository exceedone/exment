<?php

namespace Exceedone\Exment\Storage\Adapter;

interface ExmentAdapterInterface
{
    public function getUrl($path);

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey);
}
