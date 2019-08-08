<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\Sftp\SftpAdapter;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;

class ExmentAdapterSftp extends SftpAdapter implements ExmentAdapterInterface
{
    use PluginCloudTrait;

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
        return new self(config('filesystems.disks.sftp'));
    }
}
