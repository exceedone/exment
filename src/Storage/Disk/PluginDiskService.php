<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Enums\Driver;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

class PluginDiskService extends DiskServiceBase
{
    // @phpstan-ignore-next-line
    protected $plugin;
    // @phpstan-ignore-next-line
    protected $now;

    /**
     * Whether this service copied the plugin from the cloud disk
     *
     * @var bool
     */
    protected $downloaded = false;

    // @phpstan-ignore-next-line
    public function __construct(...$args)
    {
        $this->now = date('YmdHis');
        $this->initDiskService(isset($args[0]) ? $args[0] : null);
    }

    // @phpstan-ignore-next-line
    public function initDiskService($plugin)
    {
        $this->plugin = $plugin;
        $path = isset($plugin) ? $plugin->getPath() : null;

        // the plugin disk is made only when it is used: a request that finds the local copy up to
        // date never reads it, and a cloud disk is made with its client (ISS00413)
        $this->diskItem = new DiskServiceItem(function () {
            return Storage::disk(Define::DISKNAME_PLUGIN_SYNC);
        }, $path, $path);
        $this->tmpDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_ADMIN_TMP), $path, $this->now);
        $this->localSyncDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_LOCAL), $path, $path);
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

        // a plugin on the local disk is read where it is. Told by the setting, not by the disk:
        // making a cloud disk to learn its kind cost a client and a request per plugin (ISS00413).
        // Another setting checks the local copy below; a disk that turns out local is then copied
        // once, as a cloud one is.
        if (static::isLocalDriverSetting()) {
            return false;
        }

        /// get plugin directory
        $pathDir = $this->plugin->getPath();

        // if not has local sync disk
        $localSyncDisk = $this->localSyncDiskItem()->disk();
        if (!$localSyncDisk->exists($pathDir)) {
            return true;
        }

        // get "updated_at.txt" from tmp disk
        $updated_at_path = path_join($pathDir, 'updated_at.txt');
        if (!$localSyncDisk->exists($updated_at_path)) {
            return true;
        }

        // read text
        $updated_at = $localSyncDisk->get($updated_at_path);

        if ($updated_at != $this->plugin->updated_at->format('YmdHis')) {
            return true;
        }

        return false;
    }

    /**
     * Whether the plugin is on a cloud disk and its local copy was up to date, so it was not copied.
     * Then the local copy is read, and the cloud disk need not be asked whether the plugin is there:
     * each question is a request to the cloud, several times per plugin per page (ISS00413).
     */
    public function isLocalCopyCurrent(): bool
    {
        return !static::isLocalDriverSetting() && !$this->downloaded && !$this->isNeedDownload();
    }

    /**
     * Whether the plugins are set to be kept on the local disk (EXMENT_DRIVER_PLUGIN, local by default).
     * Another setting is a cloud disk, whose files are copied to the local disk.
     */
    protected static function isLocalDriverSetting(): bool
    {
        return config('exment.driver.plugin', Driver::LOCAL) === Driver::LOCAL;
    }

    // @phpstan-ignore-next-line
    protected function isSetUpdatedAt()
    {
        return false;
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
     * copy file from disk to tmp disk
     *
     * @return true
     */
    protected function sync()
    {
        ///// copy to sync disk
        $diskItem = $this->diskItem();
        $disk = $diskItem->disk();
        $localSyncDiskItem = $this->localSyncDiskItem();
        $localSyncDisk = $localSyncDiskItem->disk();

        /// get directory
        $dirName = $diskItem->dirName();
        $localSyncDirName = $localSyncDiskItem->dirName();

        // get file list
        $files = $disk->allFiles($dirName);
        foreach ($files as $file) {
            // copy from crowd to local
            $stream = $disk->readStream($file);
            if ($localSyncDisk->exists($file)) {
                $localSyncDisk->delete($file);
            }
            $localSyncDisk->writeStream($file, $stream);

            try {
                fclose($stream);
            } catch (\Exception $ex) {
            }
        }

        // create updated_at file
        $localSyncDisk->put(path_join($localSyncDirName, 'updated_at.txt'), $this->plugin->updated_at->format('YmdHis'));
        $this->downloaded = true;

        return true;
    }
}
