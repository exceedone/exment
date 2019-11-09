<?php
namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Storage\Disk\BackupDiskService;
use Illuminate\Support\Facades\Storage;

trait BackupRestoreTrait
{
    public $diskService;
    // protected $basename;

    // /**
    //  * list(Stored Backup file) dir name
    //  *
    //  * @return string
    //  */
    // protected function listDirName()
    // {
    //     return 'list';
    // }
    
    // /**
    //  * list(Stored Backup file) zip name
    //  *
    //  * @return string
    //  */
    // protected function listZipName()
    // {
    //     return path_join($this->listDirName(), $this->zipName());
    // }
    
    // /**
    //  * temporary(local) directory name
    //  *
    //  * @return string
    //  */
    // protected function tmpDirName()
    // {
    //     return $this->basename;
    // }

    // /**
    //  * temporary(local) directory full path
    //  *
    //  * @return string
    //  */
    // protected function tmpDirFullPath()
    // {
    //     return static::tmpDisk()->path($this->tmpDirName());
    // }

    // /**
    //  * temporary(local) zip file name
    //  *
    //  * @return string
    //  */
    // protected function zipName()
    // {
    //     return $this->basename . '.zip';
    // }

    // /**
    //  * temporary(local) zip full path
    //  *
    //  * @return string
    //  */
    // protected function zipFullPath()
    // {
    //     return static::tmpDisk()->path($this->zipName());
    // }

    protected function disk()
    {
        return $this->diskService->disk();
    }

    protected function tmpDisk()
    {
        return $this->diskService->tmpDisk();
    }

    protected function initBackupRestore($basename = null)
    {
        $this->diskService = new BackupDiskService($basename);

        // $this->basename = $basename ?? date('YmdHis');

        // // create temporary folder if not exists
        // if (!static::tmpDisk()->exists($this->tmpDirName())) {
        //     static::tmpDisk()->makeDirectory($this->tmpDirName(), 0755, true);
        // }

        // // create zip folder if not exists
        // if (!static::disk()->exists($this->listDirName())) {
        //     static::disk()->makeDirectory($this->listDirName(), 0755, true);
        // }
    }
}
