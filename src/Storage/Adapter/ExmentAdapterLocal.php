<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Adapter\Local;

class ExmentAdapterLocal extends Local implements ExmentAdapterInterface
{
    use AdapterTrait;
    
    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $config = static::mergeFileConfig('filesystems.disks.local', "filesystems.disks.$mergeFrom", $mergeFrom);

        return new self(array_get($config, 'root'));
    }
    
    public static function getMergeConfigKeys(string $mergeFrom, array $options = []) : array
    {
        return [];
    }
}
