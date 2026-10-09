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
    // @phpstan-ignore-next-line
    protected $diskItem;

    /**
     * tmp disk Item
     *
     */
    // @phpstan-ignore-next-line
    protected $tmpDiskItem;

    /**
     * tmp disk Item
     *
     */
    // @phpstan-ignore-next-line
    protected $localSyncDiskItem;

    /**
     * Whether is this driver isNeedDownload
     */
    // @phpstan-ignore-next-line
    public $isNeedDownload = null;

    // @phpstan-ignore-next-line
    public function diskItem()
    {
        return $this->diskItem;
    }

    // @phpstan-ignore-next-line
    public function tmpDiskItem()
    {
        return $this->tmpDiskItem;
    }

    // @phpstan-ignore-next-line
    public function localSyncDiskItem()
    {
        return $this->localSyncDiskItem;
    }

    /**
     * Upload to crowd disk
     *
     * @return void
     */
    // @phpstan-ignore-next-line
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

    // @phpstan-ignore-next-line
    abstract protected function isNeedDownload();
    // @phpstan-ignore-next-line
    abstract protected function isDeleteTmpAfterExecute();

    /**
     * @return boolean
     */
    abstract protected function sync();
}
