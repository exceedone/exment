<?php

namespace Exceedone\Exment\Storage\Disk;

/**
 * Disk Service.
 * prefix:::
 * nothing: cloud, local, etc. target adapter.
 * "tmp": temporary directory. display upload etc. almost admin_tmp.
 * "localSync": If execute ex. plugin, we have to download php files. localSync is target directory.
 */
abstract class DiskServiceBase
{
    /**
     * default disk Item
     *
     */
    protected $diskItem;

    /**
     * tmp disk Item
     *
     */
    protected $tmpDiskItem;

    /**
     * tmp disk Item
     *
     */
    protected $localSyncDiskItem;

    /**
     * Whether is this driver isNeedDownload
     */
    public $isNeedDownload = null;

    public function diskItem()
    {
        return $this->diskItem;
    }

    public function tmpDiskItem()
    {
        return $this->tmpDiskItem;
    }

    public function localSyncDiskItem()
    {
        return $this->localSyncDiskItem;
    }

    /**
     * Upload to crowd disk
     *
     * @return void
     */
    public function upload($file)
    {
        foreach ((array)$file as $key => $value) {
            // if $key is not numeric(string), copy from and to
            if (!is_numeric($key)) {
                $from = $key;
                $to = $value;
            }
            // simple array, same from and to path
            else {
                $from = $value;
                $to = $value;
            }

            $stream = $this->tmpDiskItem()->disk()->readStream($from);

            $this->diskItem()->disk()->delete($to);

            $this->diskItem()->disk()->writeStream($to, $stream);

            try {
                fclose($stream);
            } catch (\Exception $ex) {
            } catch (\Throwable $ex) {
            }
        }
    }

    /**
     * delete tmp directory
     *
     * @return void
     */
    public function deleteTmpDirectory()
    {
        if (!$this->isDeleteTmpAfterExecute()) {
            return;
        }

        $this->tmpDiskItem()->disk()->delete($this->tmpDiskItem()->filePath());
        deleteDirectory($this->tmpDiskItem()->disk(), $this->tmpDiskItem()->dirName());
    }

    /**
     * copy file from disk to tmp disk
     *
     * @return void
     */
    /**
     * @return boolean
     */
    public function syncFromDisk()
    {
        if (!$this->isNeedDownload()) {
            return false;
        }

        return $this->sync();
    }

    abstract protected function isNeedDownload();
    abstract protected function isDeleteTmpAfterExecute();

    /**
     * @return boolean
     */
    abstract protected function sync();
}
