<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Storage\Disk\BackupDiskService;

trait BackupRestoreTrait
{
    // @phpstan-ignore-next-line
    protected $diskService;

    /** The progress and the log of the run (ISS02943); none: nothing is recorded. */
    protected ?RunProgress $progress = null;

    /**
     * @return $this
     */
    public function setProgress(?RunProgress $progress)
    {
        $this->progress = $progress;
        return $this;
    }

    public function progress(): RunProgress
    {
        return $this->progress ??= RunProgress::none();
    }

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
