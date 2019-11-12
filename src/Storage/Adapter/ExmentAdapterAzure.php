<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\Driver;

class ExmentAdapterAzure extends AzureBlobStorageAdapter implements ExmentAdapterInterface
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
    public static function getAdapter($app, $config, $driver)
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = Driver::mergeFileConfig('filesystems.disks.azure', "filesystems.disks.$mergeFrom", $mergeFrom);

        $key = "DefaultEndpointsProtocol=https;AccountName=" . array_get($mergeConfig, 'account') . ";AccountKey=" . array_get($mergeConfig, 'key') . ";";
        $client = BlobRestProxy::createBlobService($key);
        return new self($client, array_get($mergeConfig, 'container'));
    }
}
