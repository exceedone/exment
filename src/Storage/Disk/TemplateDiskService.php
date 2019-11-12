<?php

namespace Exceedone\Exment\Storage\Disk;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

class TemplateDiskService extends DiskServiceBase
{
    public function __construct(...$args){
        if(isset($args[0]) && is_array($args[0])){
            $this->fileName = array_get($args[0], 'template_name');
        }

        $now = date('YmdHis');
        $this->tmpFileName = $now;
        $this->localSyncFileName = $now;

        $this->initializeDirectory();
    }

    public function fileName($fileName){
        $this->fileName = $fileName;

        return $this;
    }

    /**
     * return this disk
     *
     * @return void
     */
    public function disk(){
        return Storage::disk(Define::DISKNAME_TEMPLATE_SYNC);
    }

    /**
     * return Tmp(for upload and remove) Disk
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
        return $this->tmpDisk();
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
        return $this->fileName;
    }
    
    /**
     * Whether needs download from clowd
     *
     * @return boolean
     */
    protected function isNeedDownload(){
        return true;
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
     * copy file from disk to localSyncDisk disk
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
            $localSyncDisk->writeStream(path_join($localSyncDirName, $file), $stream);
        }
        
        return true;
    }
}
