<?php

namespace Exceedone\Exment\Storage\Adapter;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\Driver;

class ExmentAdapterS3 extends AwsS3Adapter implements ExmentAdapterInterface
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
        $mergeConfig = Driver::mergeFileConfig('filesystems.disks.s3', "filesystems.disks.$mergeFrom", $mergeFrom);

        $client = new S3Client([
            'credentials' => [
                'key'    => array_get($config, 'key'),
                'secret' => array_get($config, 'secret'),
            ],
            'region' => array_get($config, 'region'),
            'version' => 'latest',
        ]);
        return new self($client, array_get($config, 'bucket'));
    }
}
