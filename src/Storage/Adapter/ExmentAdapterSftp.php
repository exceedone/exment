<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Sftp\SftpAdapter;

use Exceedone\Exment\Model\File;

class ExmentAdapterSftp extends SftpAdapter implements ExmentAdapterInterface
{
    use AdapterTrait;
    
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
        $mergeConfig = static::mergeFileConfig('filesystems.disks.sftp', "filesystems.disks.$mergeFrom", $mergeFrom);
        $mergeConfig['driver'] = 'sftp';

        $driver = new self($mergeConfig);
        return $driver;
    }
    
    public static function getMergeConfigKeys(string $mergeFrom, array $options = []) : array
    {
        return [
            'root' => config('exment.rootpath.sftp.' . $mergeFrom),
        ];
    }
}
