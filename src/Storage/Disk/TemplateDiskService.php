<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

class TemplateDiskService extends DiskServiceBase
{
    protected $now;

    public function __construct(...$args)
    {
        $this->now = date('YmdHis');
        $this->initDiskService(isset($args[0]) ? $args[0] : null);
    }

    public function initDiskService($template_name)
    {
        $this->diskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_TEMPLATE_SYNC), $template_name, $template_name);
        $this->tmpDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_ADMIN_TMP), $template_name, $this->now);
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
        $localSyncDisk = $localSyncDiskItem->Disk();

        // download zip
        \Exment::makeDirectoryDisk($localSyncDisk, $localSyncDiskItem->dirName());

        // get file list
        $files = $disk->allFiles($diskItem->dirName());
        foreach ($files as $file) {
            // copy from crowd to local
            $stream = $disk->readStream($file);
            if ($localSyncDisk->exists($file)) {
                $localSyncDisk->delete($file);
            }

            $localPath = path_join($localSyncDiskItem->dirName(), $file);
            $localSyncDisk->writeStream($localPath, $stream);

            try {
                fclose($stream);
            } catch (\Exception $ex) {
            }
        }

        return true;
    }
}
