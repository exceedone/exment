<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class ExmentAdapterAzure extends AzureBlobStorageAdapter implements ExmentAdapterInterface
{
    use AdapterTrait;

    /**
     * get adapter class
     */
    // @phpstan-ignore-next-line
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeConfig = static::getConfig($config);

        $key = "DefaultEndpointsProtocol=https;AccountName=" . array_get($mergeConfig, 'account') . ";AccountKey=" . array_get($mergeConfig, 'key') . ";";
        $client = BlobRestProxy::createBlobService($key);
        return new self($client, array_get($mergeConfig, 'container'));
    }

    // @phpstan-ignore-next-line
    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array
    {
        return [
            'container' => config('exment.rootpath.azure.' . $mergeFrom),
        ];
    }

    /**
     * Get config. Execute merge.
     *
     * @param array $config
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function getConfig($config): array
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = static::mergeFileConfig('filesystems.disks.azure', "filesystems.disks.$mergeFrom", $mergeFrom);
        return $mergeConfig;
    }
}
