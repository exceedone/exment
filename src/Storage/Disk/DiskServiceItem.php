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
     * @param Filesystem|\Closure(): Filesystem $disk  a closure makes the disk when it is first used
     *        (a cloud disk is made with its client and credentials, which a check of the local copy
     *        does not need)
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
     * @var Filesystem|\Closure(): Filesystem disk, or what makes it
     *
     */
    protected $disk;

    /**
     * The disk, made now if it was given as a closure.
     *
     * @return Filesystem
     */
    protected function resolvedDisk()
    {
        if ($this->disk instanceof \Closure) {
            $this->disk = ($this->disk)();
        }
        return $this->disk;
    }


    // @phpstan-ignore-next-line
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
        $disk = $this->resolvedDisk();
        if (!is_null($this->dirName) && !$disk->exists($this->dirName)) {
            \Exceedone\Exment\Facades\ExmentFacade::makeDirectoryDisk($disk, $this->dirName);
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
        return $this->resolvedDisk();
    }

    /**
     * Whether this disk's driver is local.
     *
     * @return boolean
     */
    public function isDriverLocal()
    {
        // the disk itself, not disk(): the kind of a driver needs no directory, and making sure of
        // one asks a cloud disk (S3) over the network. Every request asked so for each plugin
        // (PluginDiskService::isNeedDownload), 30-80 ms each on Exment SaaS (ISS00413).
        $disk = $this->resolvedDisk();
        if (!method_exists($disk, 'getAdapter')) {
            return false;
        }
        return $disk->getAdapter() instanceof \League\Flysystem\Local\LocalFilesystemAdapter;
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
