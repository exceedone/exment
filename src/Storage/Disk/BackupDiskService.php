<?php

namespace Exceedone\Exment\Storage\Disk;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

class BackupDiskService extends DiskServiceBase
{
    public function __construct(...$args){
        $now = date('YmdHis');
        $this->fileName = isset($args[0]) ? $args[0] : $now;
        $this->tmpFileName = $now;
        $this->localSyncFileName = $now;

        $this->initializeDirectory();
    }

    /**
     * return this disk
     *
     * @return void
     */
    public function disk(){
        return Storage::disk(Define::DISKNAME_BACKUP_SYNC);
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
        return $this->fileName . '.zip';
    }

    /**
     * tmp file name with extension
     *
     * @return string
     */
    public function tmpFileNameExtension()
    {
        return $this->tmpFileName . '.zip';
    }

    /**
     * list(Stored Backup file) dir name
     *
     * @return string
     */
    public function dirName()
    {
        return 'list';
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

        // download zip
        if (!$localSyncDisk->exists($this->localSyncDiskDirName())) {
            $localSyncDisk->makeDirectory($this->localSyncDiskDirName(), 0755, true);
        }
        
        $stream = $disk->readStream($this->filePath());
        $localSyncDisk->writeStream($this->localSyncDiskFilePath(), $stream);

        // open new zip file
        $zip = new \ZipArchive();
        if ($zip->open($this->localSyncDiskFileFullPath()) === true) {
            $zip->extractTo($this->localSyncDiskDirFullPath());
            $zip->close();
        }

        $localSyncDisk->delete($this->localSyncDiskFilePath());
        
        return true;
    }
}
