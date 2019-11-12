<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Sftp\SftpAdapter;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\Driver;

class ExmentAdapterSftp extends SftpAdapter implements ExmentAdapterInterface
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
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = Driver::mergeFileConfig('filesystems.disks.sftp', "filesystems.disks.$mergeFrom", $mergeFrom);
        $mergeConfig['driver'] = 'sftp';

        $driver = new self($mergeConfig);
        return $driver;
    }
}
