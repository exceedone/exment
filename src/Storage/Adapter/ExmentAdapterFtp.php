<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Adapter\Ftp;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\Driver;

class ExmentAdapterFtp extends Ftp implements ExmentAdapterInterface
{
    use CloudTrait;

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
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = Driver::mergeFileConfig('filesystems.disks.ftp', "filesystems.disks.$mergeFrom", $mergeFrom);
        
        $driver = new self($mergeConfig);
        $driver->setTmpDisk($config);

        return $driver;
    }
}
