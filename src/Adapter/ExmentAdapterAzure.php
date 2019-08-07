<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;

class ExmentAdapterAzure extends AzureBlobStorageAdapter implements ExmentAdapterInterface
{
    /**
     * Get URL using File class
     */
    public function getUrl($path)
    {
        return File::getUrl($path);
    }

    public function getPluginFullPath($plugin, ...$pass_array){
        // get plugin root path
        $path = $plugin->getPath();

        // first, download from clowd
        $disk = \Storage::disk(Define::DISKNAME_ADMIN);

        // get file list
        $files = $disk->allFiles($path);

        $stream = $disk->readStream($path);

        // write admin_tmp

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
