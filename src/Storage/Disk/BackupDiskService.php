<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

class BackupDiskService extends DiskServiceBase
{
    public function __construct(...$args)
    {
        $now = date('YmdHis');
        $fileName = isset($args[0]) ? $args[0] : $now;

        $this->diskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_BACKUP_SYNC), "$fileName.zip", 'list');
        $this->tmpDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_ADMIN_TMP), "$now.zip", $now);
        $this->localSyncDiskItem = $this->tmpDiskItem;
    }

    /**
     * Whether needs download from clowd
     *
     * @return boolean
     */
    protected function isNeedDownload()
    {
        if (!is_null($this->isNeedDownload)) {
            return $this->isNeedDownload;
        }

        if ($this->diskItem()->isDriverLocal()) {
            return false;
        }

        return true;
    }

    /**
     * is delete tmp file and directory after execute
     *
     * @return boolean
     */
    protected function isDeleteTmpAfterExecute()
    {
        return true;
    }

    /**
     * copy file from disk to localSyncDisk disk
     *
     * @return true
     */
    protected function sync()
    {
        ///// copy to sync disk
        $diskItem = $this->diskItem();
        $localSyncDiskItem = $this->localSyncDiskItem();

        $disk = $diskItem->disk();
        $localSyncDisk = $localSyncDiskItem->disk();

        // download zip
        \Exment::makeDirectoryDisk($localSyncDisk, $localSyncDiskItem->dirName());

        $stream = $disk->readStream($diskItem->filePath());
        $localSyncDisk->writeStream($localSyncDiskItem->filePath(), $stream);
        try {
            fclose($stream);
        } catch (\Exception $ex) {
        }

        // open new zip file
        $zip = new \ZipArchive();
        if ($zip->open($localSyncDiskItem->fileFullPath()) === true) {
            $zip->extractTo($localSyncDiskItem->dirFullPath());
            $zip->close();
        }

        $localSyncDisk->delete($localSyncDiskItem->filePath());

        return true;
    }
}
