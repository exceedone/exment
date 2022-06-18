<?php

namespace Exceedone\Exment\Storage\Adapter;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class ExmentAdapterS3 extends AwsS3V3Adapter implements ExmentAdapterInterface
{
    use AdapterTrait;
    
    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = static::mergeFileConfig('filesystems.disks.s3', "filesystems.disks.$mergeFrom", $mergeFrom);

        // create client config
        $clientConfig = [
            'credentials' => [
                'key'    => array_get($mergeConfig, 'key'),
                'secret' => array_get($mergeConfig, 'secret'),
            ],
            'region' => array_get($mergeConfig, 'region'),
            'version' => 'latest',
            'bucket' => array_get($mergeConfig, 'bucket'),
        ];

        foreach (['endpoint', 'url'] as $key) {
            if (array_key_value_exists($key, $mergeConfig)) {
                $clientConfig[$key] = $mergeConfig[$key];
            }
        }

        $client = new S3Client($clientConfig);
        return new self($client, array_get($mergeConfig, 'bucket'));
    }

    public static function getMergeConfigKeys(string $mergeFrom, array $options = []) : array
    {
        return [
            'bucket' => config('exment.rootpath.s3.' . $mergeFrom),
        ];
    }

    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    public function applyPathPrefix(string $path) : string
    {
        return $this->localPrefixer->prefixPath($path);
    }
}
