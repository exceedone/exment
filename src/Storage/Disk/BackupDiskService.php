<?php

namespace Exceedone\Exment\Storage\Disk;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

class BackupDiskService
{
    public function __construct(...$args){
        $now = date('YmdHis');
        $this->fileName = isset($args[0]) > 0 ? $args[0] : $now;
        $this->tmpFileName = $now;
        
        if (!$this->tmpDisk()->exists($this->tmpDirName())) {
            $this->tmpDisk()->makeDirectory($this->tmpDirName(), 0755, true);
        }

        // create zip folder if not exists
        if (!$this->disk()->exists($this->dirName())) {
            $this->disk()->makeDirectory($this->dirName(), 0755, true);
        }
    }

    /**
     * file name. not extension
     *
     * @var string file name
     */
    protected $fileName;

    /**
     * tmp file name. not extension
     *
     * @var string file name
     */
    protected $tmpFileName;

    /**
     * return this disk
     *
     * @return void
     */
    public function disk(){
        return Storage::disk(Define::DISKNAME_BACKUP);
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
     * directory full path
     *
     * @return string
     */
    public function dirFullPath()
    {
        return $this->disk()->path($this->dirName());
    }

    /**
     * file path name with extension
     *
     * @return string
     */
    public function filePath()
    {
        return path_join($this->dirName(), $this->fileNameExtension());
    }

    /**
     * file full path name with extension
     *
     * @return string
     */
    public function fileFullPath()
    {
        return $this->disk()->path($this->filePath());
    }

    /**
     * temporary(local) directory name
     *
     * @return string
     */
    public function tmpDirName()
    {
        return $this->fileName;
    }

    /**
     * temporary(local) directory full path
     *
     * @return string
     */
    public function tmpDirFullPath()
    {
        return $this->tmpDisk()->path($this->tmpDirName());
    }

    /**
     * tmp file path name with extension
     *
     * @return string
     */
    public function tmpFilePath()
    {
        return path_join($this->tmpDirName(), $this->tmpFileNameExtension());
    }

    /**
     * temporary(local) file full path
     *
     * @return string
     */
    public function tmpFileFullPath()
    {
        return $this->tmpDisk()->path($this->tmpFilePath());
    }

    /**
     * Whether needs download from clowd
     *
     * @return boolean
     */
    protected function isNeedDownload(){
        return true;
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
     * Upload to crowd disk
     *
     * @return void
     */
    public function upload(){
        $stream = $this->tmpDisk()->readStream($this->tmpFilePath());
        $this->disk()->writeStream($this->filePath(), $stream);
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
        
        $success = $this->tmpDisk()->deleteDirectory($this->tmpDirName());
        $this->tmpDisk()->delete($this->tmpFilePath());
    }

    /**
     * copy file from disk to tmp disk
     *
     * @return void
     */
    public function syncFromDisk()
    {
        if(!$this->isNeedDownload()){
            //TODO:path
            return;
        }

        ///// copy to sync disk
        $disk = $this->disk();
        $tmpDisk = $this->tmpDisk();

        // download zip
        // TODO:for backup. 
        if (!$tmpDisk->exists($this->tmpDirName())) {
            $tmpDisk->makeDirectory($this->tmpDirName(), 0755, true);
        }
        
        $stream = $disk->readStream($this->filePath());
        $tmpDisk->writeStream($this->tmpFilePath(), $stream);

        // open new zip file
        $zip = new \ZipArchive();
        if ($zip->open($this->tmpFileFullPath()) === true) {
            $zip->extractTo($this->tmpDirFullPath());
            $zip->close();
        }

        $this->tmpDisk()->delete($this->tmpFilePath());
        
        return true;



        /// get directory
        $dirFullPath = $this->dirFullPath();
        $tmpDirFullPath = $this->tmpDirFullPath();

        // // remove in tmp disk
        $files = $tmpDisk->allFiles($tmpDirFullPath);
        foreach ($files as $file) {
            $tmpDisk->delete($file);
        }

        // get file list
        $files = $disk->allFiles($dirFullPath);
        // TODO:for plugin. 
        // foreach ($files as $file) {
        //     // copy from crowd to local
        //     $stream = $disk->readStream($file);
        //     $tmpDisk->writeStream($file, $stream);
        // }
        
        // create updated_at file
        if($this->isSetUpdatedAt()){
            $tmpDisk->put(path_join($pathDir, 'updated_at.txt'), $plugin->updated_at->format('YmdHis'));
        }

        return getFullpath($pathDir, Define::DISKNAME_PLUGIN_LOCAL);
    }

}
