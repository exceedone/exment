<?php

namespace Exceedone\Exment\Adapter;

interface ExmentAdapterInterface
{
    /**
     * get adapter class
     */
    public static function getAdapter($app, $config);
}
