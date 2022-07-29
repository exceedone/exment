<?php

namespace Exceedone\Exment\Storage\Adapter;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class ExmentAdapterS3 extends AwsS3V3Adapter implements ExmentAdapterInterface
{
    use AdapterTrait;

    /**
     * Override EXTRA_METADATA_FIELDS because EXTRA_METADATA_FIELDS is private
     * @var string[]
     */
    protected const EXTRA_METADATA_FIELDS = [
        'Metadata',
        'StorageClass',
        'ETag',
        'VersionId',
    ];

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeConfig = static::getConfig($config);

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

    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array
    {
        return [
            'bucket' => config('exment.rootpath.s3.' . $mergeFrom),
        ];
    }

    /**
     * Get config. Execute merge.
     *
     * @param array $config
     * @return array
     */
    public static function getConfig($config): array
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = static::mergeFileConfig('filesystems.disks.s3', "filesystems.disks.$mergeFrom", $mergeFrom);
        if (!array_key_exists('ACL', $mergeConfig)) {
            $mergeConfig['ACL'] = 'private';
        }
        return $mergeConfig;
    }
}
