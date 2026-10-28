<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Services\ZipService;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use File;

class Restore
{
    use BackupRestoreTrait;
    use EnvTrait;

    /**
     * Whether the last restore skipped the backup's config files.
     *
     * @var bool
     */
    protected $configSkipped = false;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Get restore target list
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public function list(): array
    {
        $disk = $this->disk();

        // get all archive files
        // @phpstan-ignore-next-line
        $files = array_filter($disk->files('list'), function ($file) {
            return preg_match('/list\/.+\.zip$/i', $file);
        });
        // edit table row data
        $rows = [];
        foreach ($files as $file) {
            $rows[] = [
                'file_key' => pathinfo($file, PATHINFO_FILENAME),
                'file_name' => mb_basename($file),
                'file_size' => bytesToHuman($disk->size($file)),
                'created' => date("Y/m/d H:i:s", $disk->lastModified($file))
            ];
        }

        return $rows;
    }

    /**
     * Execute restore.
     *
     * @param $file string|null target file
     * @param bool|null $tmp if 1, unzip and restore
     * @return int
     * @throws \Exception
     */
    // @phpstan-ignore-next-line
    public function execute($file = null, ?bool $tmp = null)
    {
        $this->configSkipped = false;

        try {
            \Artisan::call('down');

            // check backup execute
            \ExmentDB::checkBackup();

            // unzip backup file
            $this->unzipFile($file, $tmp);

            $result = 0;

            // restore table definition
            $this->restoreDatabase();

            // import tsv file to table
            $this->importTsv();

            // copy directory to temporary folder
            if (!$this->copyFiles()) {
                $result = -1;
            }

            // copy env
            $this->updateEnv();

            System::clearCache();

            return $result;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            \Artisan::call('up');
            $this->diskService->deleteTmpDirectory();
        }
    }


    /**
     * insert table data from backup tsv files.
     *
     */
    // @phpstan-ignore-next-line
    protected function importTsv()
    {
        \ExmentDB::importTsv($this->diskService->tmpDiskItem()->dirFullPath());
    }

    /**
     * copy folder from temp directory
     *
     * @return bool true:success/false:fail
     */
    protected function copyFiles()
    {
        $result = true;
        $tmpDisk = $this->diskService->tmpDiskItem()->disk();

        $directories = $tmpDisk->allDirectories($this->diskService->tmpDiskItem()->dirName());

        foreach ($directories as $directory) {
            // check target key name
            $splits = explode("/", $directory);
            if (count($splits) < 2) {
                continue;
            }
            $keyname = $splits[1];

            $setting = BackupTarget::dirOrDisk($splits);
            if (is_null($setting)) {
                continue;
            }

            // config files of another Laravel version would overwrite the current ones and
            // stop the application from starting (e.g. config/app.php listing providers
            // a Laravel 12 skeleton no longer has)
            if ($setting[2] == BackupTarget::CONFIG && !$this->canRestoreConfig()) {
                $this->configSkipped = true;
                continue;
            }

            $fromDirectory = $tmpDisk->path(path_join($this->diskService->tmpDiskItem()->dirName(), $keyname));

            $s = $setting[0];
            // is local file
            if (is_string($s)) {
                $topath = $s;
                $success = \File::copyDirectory($fromDirectory, $topath);
                if (!$success) {
                    $result = false;
                }
            }
            // is croud file
            else {
                $disk = $setting[0];

                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting[1]);

                \Exment::makeDirectoryDisk($this->tmpDisk(), $to);

                $files = $tmpDisk->files($directory);
                foreach ($files as $file) {
                    $path = path_ltrim($file, $to);
                    // copy from crowd to local
                    $stream = $tmpDisk->readStream($file);
                    $disk->delete($path);
                    $disk->writeStream($path, $stream);

                    try {
                        fclose($stream);
                    } catch (\Exception $ex) {
                    } catch (\Throwable $ex) {
                    }
                }
            }
        }

        if ($this->configSkipped) {
            \Log::warning('Exment: restore skipped the config files of the backup: ' . $this->getBackupLaravelVersionText());
        }

        return $result;
    }

    /**
     * Whether the last restore skipped the backup's config files.
     *
     * @return bool
     */
    public function isConfigSkipped(): bool
    {
        return $this->configSkipped;
    }

    /**
     * Whether the backup's config files fit this installation.
     *
     * Only a backup taken on the same Laravel major version restores them. A backup without
     * version information was taken before it was recorded (Exment v6 and older): its config
     * files are of Laravel 10 or older.
     *
     * @return bool
     */
    protected function canRestoreConfig(): bool
    {
        $backupVersion = $this->getBackupLaravelVersion();
        if (is_nullorempty($backupVersion)) {
            return false;
        }

        return $this->getMajorVersion($backupVersion) === $this->getMajorVersion(app()->version());
    }

    /**
     * Get the Laravel version the backup was taken on, null if the backup does not record it.
     *
     * @return string|null
     */
    protected function getBackupLaravelVersion(): ?string
    {
        $file = path_join($this->diskService->tmpDiskItem()->dirFullPath(), Define::BACKUP_VERSION_FILE);
        if (!\File::exists($file)) {
            return null;
        }

        $versions = json_decode_ex(\File::get($file), true);
        $laravel = is_array($versions) ? array_get($versions, 'laravel') : null;

        return is_string($laravel) ? $laravel : null;
    }

    /**
     * @return string
     */
    protected function getBackupLaravelVersionText(): string
    {
        return sprintf('backup Laravel version: %s, current: %s', $this->getBackupLaravelVersion() ?? '(not recorded)', app()->version());
    }

    /**
     * @param string $version
     * @return int|null
     */
    protected function getMajorVersion(string $version): ?int
    {
        return preg_match('/^v?(\d+)/', $version, $matches) ? intval($matches[1]) : null;
    }

    /**
     * update env data
     *
     */
    // @phpstan-ignore-next-line
    protected function updateEnv()
    {
        // get env file
        $file = path_join($this->diskService->tmpDiskItem()->dirFullPath(), '.env');
        if (!\File::exists($file)) {
            return;
        }

        $matchKeys = [
            [
                'keys' => ['EXMENT_'],
                'prefix' => true,
            ],
            [
                'keys' => ['APP_KEY', 'APP_LOCALE', 'APP_TIMEZONE'],
                'prefix' => false,
            ],
        ];

        foreach ($matchKeys as $item) {
            foreach ($item['keys'] as $key) {
                if (is_null($lines = $this->getEnv($key, $file, $item['prefix']))) {
                    continue;
                }

                foreach ($lines as $line) {
                    $this->setEnv([$line[0] => $line[1]]);
                }
            }
        }
    }

    /**
     * unzip backup file to temporary folder path.
     */
    // @phpstan-ignore-next-line
    protected function unzipFile($file, ?bool $tmp = null)
    {
        // get file
        $targetfile = array_get(pathinfo($file), 'filename');

        $this->initBackupRestore($targetfile);

        // set to tmp zip file
        if (!boolval($tmp)) {
            $this->diskService->isNeedDownload = true;
            $this->diskService->syncFromDisk();
        }
        // if tmp(call from display), copy file
        else {
            $zipPath = getFullpath($file, Define::DISKNAME_ADMIN_TMP);
            // open new zip file
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                // an unreadable zip must not look like a successful restore: execute()
                // would find an empty folder, restore nothing, still return 0, and the
                // screen would answer "restore succeeded" and log the user out.
                throw new InvalidZipEntryException(strval(exmtrans('backup.message.restore_file_error')));
            }

            $zipEntryError = ZipService::validateZipEntries($zip);
            if ($zipEntryError !== null) {
                $zip->close();
                throw new InvalidZipEntryException($zipEntryError);
            }

            $zip->extractTo($this->diskService->tmpDiskItem()->dirFullPath());
            $zip->close();
        }

        return true;
    }


    /**
     * restore backup table definition and table data.
     *
     */
    // @phpstan-ignore-next-line
    protected function restoreDatabase()
    {
        \ExmentDB::restoreDatabase($this->diskService->tmpDiskItem()->dirFullPath());
    }
}
