<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Enums\Driver;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class PluginDiskService extends DiskServiceBase
{
    // @phpstan-ignore-next-line
    protected $plugin;
    // @phpstan-ignore-next-line
    protected $now;
    /**
     * Set to true when this instance detected an S3 failure — callers can skip further disk ops.
     *
     * @var bool
     */
    public $syncFailed = false;

    /**
     * Per-worker cache to skip S3 freshness checks for already-synced plugins.
     * Values are plugin version timestamps (format YmdHis), or 'local' for local-driver plugins.
     * On cache hit, the stored version is compared against the current plugin updated_at;
     * if they differ the entry is cleared and a fresh sync check runs.
     *
     * NOTE: PHP-FPM static properties persist across requests on the same worker.
     * Storing the version (not a boolean) ensures stale cache entries are invalidated
     * automatically when a plugin is updated.
     *
     * @var array<string, string>
     */
    protected static $syncedPaths = [];

    /**
     * Forget what this worker remembers of the plugins (for the tests, and after a deploy).
     */
    public static function clearSyncedPaths(): void
    {
        static::$syncedPaths = [];
    }

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
        // External override hook: callers can set $this->isNeedDownload = true/false
        // to force-bypass the freshness check (used by BackupDiskService, TemplateDiskService).
        if (!is_null($this->isNeedDownload)) {
            return $this->isNeedDownload;
        }

        $cacheKey = isset($this->plugin) ? $this->plugin->getPath() : null;
        if ($cacheKey && array_key_exists($cacheKey, static::$syncedPaths)) {
            $cachedVersion = static::$syncedPaths[$cacheKey];
            if ($cachedVersion === 'local') {
                return false; // local-driver plugin — no S3 sync ever needed
            }
            if ($cachedVersion === 'failed') {
                $this->syncFailed = true;
                return false; // S3 unreachable — skip further checks this process lifetime
            }
            if ($cachedVersion === $this->plugin->updated_at->format('YmdHis')) {
                return false; // plugin unchanged since last sync on this worker
            }
            // Plugin was updated since this worker last synced it — clear stale entry
            unset(static::$syncedPaths[$cacheKey]);
        }

        // a plugin on the local disk is read where it is. Told by the setting, not by the disk:
        // making a cloud disk to learn its kind cost a client and a request per plugin (ISS00413).
        // Another setting checks the local copy below; a disk that turns out local is then copied
        // once, as a cloud one is.
        if (static::isLocalDriverSetting()) {
            if ($cacheKey) {
                static::$syncedPaths[$cacheKey] = 'local';
            }
            return false;
        }

        /// get plugin directory
        $pathDir = $this->plugin->getPath();

        // if not has local sync disk -> need download now
        $localSyncDisk = $this->localSyncDiskItem()->disk();
        if (!$localSyncDisk->exists($pathDir)) {
            return true;
        }

        // get "updated_at.txt" from local sync disk
        $updated_at_path = path_join($pathDir, 'updated_at.txt');
        if (!$localSyncDisk->exists($updated_at_path)) {
            return true;
        }

        // read text
        $updated_at = $localSyncDisk->get($updated_at_path);

        // if outdated, sync inline so the current request uses the refreshed local copy
        if ($updated_at != $this->plugin->updated_at->format('YmdHis')) {
            return true;
        }

        // up-to-date — mark cached with current version and do not download
        if ($cacheKey) {
            static::$syncedPaths[$cacheKey] = $this->plugin->updated_at->format('YmdHis');
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
     * @return bool
     */
    protected function sync()
    {
        $lockKey = 'plugin_sync_' . md5($this->plugin->getPath());
        $lock = Cache::lock($lockKey, 30);

        try {
            $result = $lock->block(10, function () {
                // double-check after acquiring lock
                if (!$this->isNeedDownload()) {
                    return false;
                }

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

                    // opcache invalidate for PHP files
                    if (substr($file, -4) === '.php' && function_exists('opcache_invalidate')) {
                        try {
                            if (method_exists($localSyncDisk, 'path')) {
                                // @phpstan-ignore-next-line
                                $localPath = $localSyncDisk->path($file);
                                if ($localPath) {
                                    @opcache_invalidate($localPath, true);
                                }
                            }
                        } catch (\Throwable $ex) {
                        }
                    }
                }

                // delete orphan local files that no longer exist on S3
                // Guard: skip if S3 returned zero files to avoid mass-deletion on connectivity failure
                if (!empty($files)) {
                    $s3FileSet = array_flip($files);
                    $localFiles = $localSyncDisk->allFiles($localSyncDirName);
                    foreach ($localFiles as $localFile) {
                        if (basename($localFile) === 'updated_at.txt') {
                            continue; // local-only marker file, never delete
                        }
                        if (!array_key_exists($localFile, $s3FileSet)) {
                            $localSyncDisk->delete($localFile);
                            if (substr($localFile, -4) === '.php' && function_exists('opcache_invalidate')) {
                                try {
                                    if (method_exists($localSyncDisk, 'path')) {
                                        // @phpstan-ignore-next-line
                                        $localPath = $localSyncDisk->path($localFile);
                                        if ($localPath) {
                                            @opcache_invalidate($localPath, true);
                                        }
                                    }
                                } catch (\Throwable $ex) {
                                }
                            }
                        }
                    }
                }

                // create updated_at file
                $localSyncDisk->put(path_join($localSyncDirName, 'updated_at.txt'), $this->plugin->updated_at->format('YmdHis'));

                // mark as synced with current version
                static::$syncedPaths[$this->plugin->getPath()] = $this->plugin->updated_at->format('YmdHis');
                $this->downloaded = true;

                return true;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $ex) {
            // Another worker is currently syncing this plugin.
            // Use the existing local copy for this request — better stale than HTTP 500.
            return false;
        }

        return $result;
    }
}
