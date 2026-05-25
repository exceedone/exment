<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class PluginDiskService extends DiskServiceBase
{
    protected $plugin;
    protected $now;
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

    public function __construct(...$args)
    {
        $this->now = date('YmdHis');
        $this->initDiskService(isset($args[0]) ? $args[0] : null);
    }

    public function initDiskService($plugin)
    {
        $this->plugin = $plugin;
        $path = isset($plugin) ? $plugin->getPath() : null;

        $this->diskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_SYNC), $path, $path);
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
            if ($cachedVersion === $this->plugin->updated_at->format('YmdHis')) {
                return false; // plugin unchanged since last sync on this worker
            }
            // Plugin was updated since this worker last synced it — clear stale entry
            unset(static::$syncedPaths[$cacheKey]);
        }

        if ($this->diskItem()->isDriverLocal()) {
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
