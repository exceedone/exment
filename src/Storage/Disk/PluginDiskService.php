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

    public function __construct(...$args){
        if(!isset($args[0])){
            return;
        }

        $this->plugin = $args[0];
        $this->tmpFileName = $this->plugin->getPath();
        $this->localSyncFileName = $this->tmpFileName;
        
        $this->initializeDirectory();
    }

    /**
     * return this disk
     *
     * @return void
     */
    public function disk(){
        return Storage::disk(Define::DISKNAME_PLUGIN_SYNC);
    }

    /**
     * return Tmp(local) Disk
     *
     * @return void
     */
    public function tmpDisk(){
        return Storage::disk(Define::DISKNAME_ADMIN_TMP);
    }

    /**
     * return local for sync Disk
     *
     * @return void
     */
    public function localSyncDisk(){
        return Storage::disk(Define::DISKNAME_PLUGIN_LOCAL);
    }

    /**
     * file name with extension
     *
     * @return string
     */
    public function fileNameExtension()
    {
        return null;
    }

    /**
     * tmp file name with extension
     *
     * @return string
     */
    public function tmpFileNameExtension()
    {
        return null;
    }

    /**
     * list(Stored Backup file) dir name
     *
     * @return string
     */
    public function dirName()
    {
        return pascalize($this->plugin->plugin_name);
    }
    
    /**
     * Whether needs download from clowd
     *
     * @return boolean
     */
    protected function isNeedDownload(){
        if($this->isDriverLocal()){
            return false;
        }

        /// get plugin directory
        $pathDir = $this->plugin->getPath();

        // if not has local sync disk
        $localSyncDisk = $this->localSyncDisk();
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
        $disk = $this->disk();
        $localSyncDisk = $this->localSyncDisk();

        /// get directory
        $dirFullPath = $this->dirFullPath();
        $localSyncDirName = $this->localSyncDirName();

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
