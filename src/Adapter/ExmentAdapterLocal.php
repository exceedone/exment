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
     * get adapter class
     */
    public static function getAdapter($app, $config)
    {
        return new self(array_get($config, 'root'));
    }
}
