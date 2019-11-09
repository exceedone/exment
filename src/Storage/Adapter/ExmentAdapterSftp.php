<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Sftp\SftpAdapter;

use Exceedone\Exment\Model\File;

class ExmentAdapterSftp extends SftpAdapter implements ExmentAdapterInterface
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
        return new self(config('filesystems.disks.sftp'));
    }
}
