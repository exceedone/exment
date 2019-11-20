<?php

namespace Exceedone\Exment\Storage\Disk;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

/**
 * Disk Service.
 * prefix:::
 * nothing: cloud, local, etc. target adapter.
 * "tmp": temporary directory. display upload etc. almost admin_tmp.
 * "localSync": If execute ex. plugin, we have to download php files. localSync is target directory.
 */
abstract class DiskServiceBase
{
    /**
     * default disk Item
     *
     */
    protected $diskItem;

    /**
     * tmp disk Item
     *
     */
    protected $tmpDiskItem;

    /**
     * tmp disk Item
     *
     */
    protected $localSyncDiskItem;

    public function diskItem(){
        return $this->diskItem;
    }
    
    public function tmpDiskItem(){
        return $this->tmpDiskItem;
    }
    
    public function localSyncDiskItem(){
        return $this->localSyncDiskItem;
    }
    
    // /**
    //  * tmp file name. not extension
    //  *
    //  * @var string file name
    //  */
    // protected $tmpFileName;
    
    // /**
    //  * local Sync file name. not extension
    //  *
    //  * @var string file name
    //  */
    // protected $localSyncFileName;
    
    // /**
    //  * create folder if not exists
    //  *
    //  * @return void
    //  */
    // protected function initializeDirectory(){
    //     if (!is_null($this->tmpDirName()) && !$this->tmpDisk()->exists($this->tmpDirName())) {
    //         $this->tmpDisk()->makeDirectory($this->tmpDirName(), 0755, true);
    //     }

    //     if (!is_null($this->localSyncDirName()) && !$this->localSyncDisk()->exists($this->localSyncDirName())) {
    //         $this->localSyncDisk()->makeDirectory($this->localSyncDirName(), 0755, true);
    //     }

    //     if (!is_null($this->dirName()) && !$this->disk()->exists($this->dirName())) {
    //         $this->disk()->makeDirectory($this->dirName(), 0755, true);
    //     }
    // }

    // /**
    //  * Whether this disk's driver is local.
    //  *
    //  * @return boolean
    //  */
    // public function isDriverLocal(){
    //     return $this->disk()->getDriver()->getAdapter() instanceof \League\Flysystem\Adapter\Local;
    // }

    // /**
    //  * directory full path
    //  *
    //  * @return string
    //  */
    // public function dirFullPath()
    // {
    //     return $this->disk()->path($this->dirName());
    // }

    // /**
    //  * file path name with extension
    //  *
    //  * @return string
    //  */
    // public function filePath()
    // {
    //     return path_join($this->dirName(), $this->fileNameExtension());
    // }

    // /**
    //  * file full path name with extension
    //  *
    //  * @return string
    //  */
    // public function fileFullPath()
    // {
    //     return $this->disk()->path($this->filePath());
    // }

    // /**
    //  * temporary(local) directory name
    //  *
    //  * @return string
    //  */
    // public function tmpDirName()
    // {
    //     return $this->tmpFileName;
    // }

    // /**
    //  * temporary(local) directory full path
    //  *
    //  * @return string
    //  */
    // public function tmpDirFullPath()
    // {
    //     return $this->tmpDisk()->path($this->tmpDirName());
    // }

    // /**
    //  * tmp file path name with extension
    //  *
    //  * @return string
    //  */
    // public function tmpFilePath()
    // {
    //     return path_join($this->tmpDirName(), $this->tmpFileNameExtension());
    // }

    // /**
    //  * temporary(local) file full path
    //  *
    //  * @return string
    //  */
    // public function tmpFileFullPath()
    // {
    //     return $this->tmpDisk()->path($this->tmpFilePath());
    // }
    
    // /**
    //  * localSync directory name
    //  *
    //  * @return string
    //  */
    // public function localSyncDirName()
    // {
    //     return $this->localSyncFileName;
    // }

    // /**
    //  * temporary(local) directory full path
    //  *
    //  * @return string
    //  */
    // public function localSyncDirFullPath()
    // {
    //     return $this->localSyncDisk()->path($this->localSyncDirName());
    // }

    // /**
    //  * localSync path name with extension
    //  *
    //  * @return string
    //  */
    // public function localSyncFilePath()
    // {
    //     return path_join($this->localSyncDirName(), $this->localSyncFileNameExtension());
    // }

    // /**
    //  * temporary(local) file full path
    //  *
    //  * @return string
    //  */
    // public function localSyncFileFullPath()
    // {
    //     return $this->localSyncDisk()->path($this->localSyncFilePath());
    // }
    
    /**
     * Upload to crowd disk
     *
     * @return void
     */
    public function upload($file){
        foreach((array)$file as $key => $value){
            // if $key is not numeric(string), copy from and to
            if(!is_numeric($key)){
                $from = $key;
                $to = $value;
            }
            // simple array, same from and to path
            else{
                $from = $value;
                $to = $value;
            }

            $stream = $this->tmpDiskItem()->disk()->readStream($from);
            
            $this->diskItem()->disk()->delete($to);

            $this->diskItem()->disk()->writeStream($to, $stream);
        }
    }

    /**
     * delete tmp directory
     *
     * @return void
     */
    public function deleteTmpDirectory(){
        if(!$this->isDeleteTmpAfterExecute()){
            return;
        }
        
        $this->tmpDiskItem()->disk()->delete($this->tmpDiskItem()->filePath());
        deleteDirectory($this->tmpDiskItem()->disk(), $this->tmpDiskItem()->dirName());
    }

    /**
     * copy file from disk to tmp disk
     *
     * @return void
     */
    public function syncFromDisk()
    {
        if (!$this->isNeedDownload()) {
            return false;
        }

        return $this->sync();
    }

}
