<?php

namespace Exceedone\Exment\Storage\Disk;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

class PluginDiskService extends DiskServiceBase
{
    protected $plugin;
    protected $now;

    public function __construct(...$args){
        $this->now = date('YmdHis');
        $this->initDiskService(isset($args[0]) ? $args[0] : null);
    }

    public function initDiskService($plugin){
        $this->plugin = $plugin;
        $path = isset($plugin) ? $plugin->getPath() : null;
        
        $this->diskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_SYNC), $path, $path);
        $this->tmpDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_ADMIN_TMP), $path, $this->now);
        $this->localSyncDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_LOCAL), $path, $path);
    }

    /**
     * Whether needs download from clowd
     *
     * @return boolean
     */
    protected function isNeedDownload(){
        if($this->diskItem()->isDriverLocal()){
            return false;
        }

        /// get plugin directory
        $pathDir = $this->plugin->getPath();

        // if not has local sync disk
        $localSyncDisk = $this->localSyncDiskItem()->disk();
        if (!$localSyncDisk->exists($pathDir)) {
            return true;
        }

        // get "updated_at.txt" from tmp disk
        $updated_at_path = path_join($pathDir, 'updated_at.txt');
        if (!$localSyncDisk->exists($updated_at_path)) {
            return true;
        }

        // read text
        $updated_at = $localSyncDisk->get($updated_at_path);

        if ($updated_at != $this->plugin->updated_at->format('YmdHis')) {
            return true;
        }

        return false;
    }

    protected function isSetUpdatedAt(){
        return false;
    }

    /**
     * is delete tmp file and directory after execute
     *
     * @return boolean
     */
    protected function isDeleteTmpAfterExecute(){
        return true;
    }

    
    /**
     * copy file from disk to tmp disk
     *
     * @return void
     */
    protected function sync()
    {
        ///// copy to sync disk
        $diskItem = $this->diskItem();
        $disk = $diskItem->disk();
        $localSyncDiskItem = $this->localSyncDiskItem();
        $localSyncDisk = $localSyncDiskItem->disk();

        /// get directory
        $dirFullPath = $diskItem->dirFullPath();
        $localSyncDirName = $localSyncDiskItem->dirName();

        // // remove in tmp disk
        $files = $localSyncDisk->allFiles($localSyncDirName);
        foreach ($files as $file) {
            $localSyncDisk->delete($file);
        }

        // get file list
        $files = $disk->allFiles($dirFullPath);
        foreach ($files as $file) {
            // copy from crowd to local
            $stream = $disk->readStream($file);
            $localSyncDisk->writeStream($file, $stream);
        }
        
        // create updated_at file
        $localSyncDisk->put(path_join($localSyncDirName, 'updated_at.txt'), $this->plugin->updated_at->format('YmdHis'));

        return true;
    }

}
