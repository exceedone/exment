<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\ZipService;
use Illuminate\Support\Facades\Storage;

class BackupDiskService extends DiskServiceBase
{
    // @phpstan-ignore-next-line
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
        if ($zip->open($localSyncDiskItem->fileFullPath()) !== true) {
            // an unreadable stored backup must not look like a successful restore:
            // the caller would keep going over an empty folder, restore nothing,
            // and still answer "restore succeeded". same rule as Restore::unzipFile().
            throw new InvalidZipEntryException(strval(exmtrans('backup.message.restore_file_error')));
        }

        $zipEntryError = ZipService::validateZipEntries($zip);
        if ($zipEntryError !== null) {
            $zip->close();
            throw new InvalidZipEntryException($zipEntryError);
        }

        $zip->extractTo($localSyncDiskItem->dirFullPath());
        $zip->close();

        $localSyncDisk->delete($localSyncDiskItem->filePath());

        return true;
    }
}
