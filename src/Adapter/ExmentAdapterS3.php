<?php

namespace Exceedone\Exment\Adapter;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;

use Exceedone\Exment\Model\File;

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
    public static function getAdapter($app, $config){
        $client = new S3Client([
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret')
            ],
            'region' => config('filesystems.disks.s3.region'),
            'version' => 'latest',
        ]);
        return new self($client, config('filesystems.disks.s3.bucket'));
    }
}
