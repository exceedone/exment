<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Storage\Disk\BackupDiskService;

trait BackupRestoreTrait
{
    protected $diskService;

    public function disk()
    {
        return $this->diskService->diskItem()->disk();
    }

    public function tmpDisk()
    {
        return $this->diskService->tmpDiskItem()->disk();
    }

    public function initBackupRestore($basename = null)
    {
        $this->diskService = new BackupDiskService($basename);

        return $this;
    }

    public function diskService()
    {
        return $this->diskService;
    }
}
