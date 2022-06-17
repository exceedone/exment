<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Local\LocalFilesystemAdapter as Local;

class ExmentAdapterLocal extends Local implements ExmentAdapterInterface
{
    use AdapterTrait;
    
    /**
     * @var array
     */
    protected static $permissions = [
        'file' => [
            'public' => 0644,
            'private' => 0600,
        ],
        'dir' => [
            // Change public permission 0755 to 0775
            'public' => 0775,
            'private' => 0700,
        ],
    ];

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
