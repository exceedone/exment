<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

/**
 * Admin file disk service. For use ex. excel image
 */
class AdminDiskService extends DiskServiceBase
{
    public function __construct(...$args)
    {
        $now = \Carbon\Carbon::now()->format('YmdHisv');
        $path = isset($args[0]) ? $args[0] : $now;

        // get dirname and file name from pathinfo
        $dirName = pathinfo($path, PATHINFO_DIRNAME);
        $fileName = pathinfo($path, PATHINFO_BASENAME);

        $this->diskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_ADMIN), $fileName, $dirName);
        $this->tmpDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_ADMIN_TMP), $now, $dirName);
        $this->localSyncDiskItem = $this->tmpDiskItem;
    }

    /**
     * Whether needs download from clowd
     *
     * @return boolean
     */
    protected function isNeedDownload()
    {
        // if (!is_null($this->isNeedDownload)) {
        //     return $this->isNeedDownload;
        // }

        // if ($this->diskItem()->isDriverLocal()) {
        //     return false;
        // }

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

        // download file
        \Exment::makeDirectoryDisk($localSyncDisk, $localSyncDiskItem->dirName());

        // only call if exists
        if (!$disk->exists($diskItem->filePath())) {
            return true;
        }
        $stream = $disk->readStream($diskItem->filePath());
        $localSyncDisk->writeStream($localSyncDiskItem->filePath(), $stream);
        try {
            fclose($stream);
        } catch (\Exception $ex) {
        }

        return true;
    }
}
