<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;

class ExmentAdapterAzure extends AzureBlobStorageAdapter implements ExmentAdapterInterface
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
        $key = "DefaultEndpointsProtocol=https;AccountName=" . config('filesystems.disks.azure.account') . ";AccountKey=" . config('filesystems.disks.azure.key') . ";";
        $client = BlobRestProxy::createBlobService($key);
        return new self($client, config('filesystems.disks.azure.container'));
    }
}
