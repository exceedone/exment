<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Storage\Disk\BackupDiskService;

trait BackupRestoreTrait
{
    // @phpstan-ignore-next-line
    protected $diskService;

    // @phpstan-ignore-next-line
    public function disk()
    {
        return $this->diskService->diskItem()->disk();
    }

    // @phpstan-ignore-next-line
    public function tmpDisk()
    {
        return $this->diskService->tmpDiskItem()->disk();
    }

    // @phpstan-ignore-next-line
    public function initBackupRestore($basename = null)
    {
        $this->diskService = new BackupDiskService($basename);

        return $this;
    }

    // @phpstan-ignore-next-line
    public function diskService()
    {
        return $this->diskService;
    }
}
