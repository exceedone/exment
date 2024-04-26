<?php

namespace Exceedone\Exment\Storage\Disk;

use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Disk Service Item.
 */
class DiskServiceItem
{
    /**
     * Construct
     *
     * @param Filesystem $disk
     * @param string|null $fileName
     * @param string|null $dirName
     */
    public function __construct($disk, $fileName, $dirName)
    {
        $this->disk = $disk;
        $this->fileName = $fileName;
        $this->dirName = $dirName;
    }

    /**
     * file name. contains extension
     *
     * @var string|null file name
     */
    protected $fileName;

    /**
     * directory name.
     *
     * @var string|null file name
     */
    protected $dirName;

    /**
     * Storage disk
     *
     * @var Filesystem disk
     *
     */
    protected $disk;


    public function fileNameNoExtension()
    {
        if (is_null($this->fileName)) {
            return null;
        }

        return pathinfo($this->fileName, PATHINFO_FILENAME);
    }

    /**
     * create folder if not exists
     *
     * @return void
     */
    protected function initializeDirectory()
    {
        if (!is_null($this->dirName) && !$this->disk->exists($this->dirName)) {
            \Exment::makeDirectoryDisk($this->disk, $this->dirName);
        }
    }

    /**
     * return this disk
     *
     * @return Filesystem
     */
    public function disk()
    {
        $this->initializeDirectory();
        return $this->disk;
    }

    /**
     * Whether this disk's driver is local.
     *
     * @return boolean
     */
    public function isDriverLocal()
    {
        return $this->disk()->getAdapter() instanceof \League\Flysystem\Local\LocalFilesystemAdapter;
    }

    /**
     * directory name
     *
     * @return string
     */
    public function dirName()
    {
        return $this->dirName;
    }

    /**
     * directory full path
     *
     * @return string
     */
    public function dirFullPath()
    {
        return $this->disk()->path($this->dirName);
    }

    /**
     * file path name with extension
     *
     * @return string
     */
    public function filePath()
    {
        return path_join($this->dirName, $this->fileName);
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
}
