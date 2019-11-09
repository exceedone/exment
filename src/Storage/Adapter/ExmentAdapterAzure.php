<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;

class ExmentAdapterAzure extends AzureBlobStorageAdapter implements ExmentAdapterInterface
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
    public static function getAdapter($app, $config, $driver)
    {
        $key = "DefaultEndpointsProtocol=https;AccountName=" . config('filesystems.disks.azure.account') . ";AccountKey=" . config('filesystems.disks.azure.key') . ";";
        $client = BlobRestProxy::createBlobService($key);
        return new self($client, config('filesystems.disks.azure.container'));
    }
}
