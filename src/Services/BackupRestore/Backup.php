<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Services\Installer\EnvTrait;

class Backup
{
    use BackupRestoreTrait;
    use EnvTrait;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Can check execute backup
     *
     * @return mixed
     */
    public function check()
    {
        return \ExmentDB::checkBackup();
    }

    /**
     * Execute backup.
     *
     * @return int
     * @throws \Exceedone\Exment\Exceptions\BackupRestoreCheckException
     */
    public function execute($target = null, bool $schedule = false)
    {
        try {
            // check backup execute
            \ExmentDB::checkBackup();

            $target = $target ?? BackupTarget::arrays();

            if (is_string($target)) {
                $target = collect(explode(",", $target))->map(function ($t) {
                    /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
                    return new BackupTarget($t) ?? null;
                })->filter()->toArray();
            }

            $this->initBackupRestore();

            // backup database tables
            if (in_array(BackupTarget::DATABASE, $target)) {
                \ExmentDB::backupDatabase($this->diskService->tmpDiskItem()->dirFullPath());
            }

            // backup directory
            if (!$this->copyFiles($target)) {
                return -1;
            }

            // archive whole folder to zip
            $this->createZip();

            // if call as batch
            if ($schedule) {
                $this->removeOldBackups();
            }

            return 0;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->diskService->deleteTmpDirectory();
        }
    }

    /**
     * copy folder to temp directory
     *
     * @return bool true:success/false:fail
     */
    protected function copyFiles($target)
    {
        // get directory paths
        $settings = collect($target)->map(function ($val) {
            return BackupTarget::dirOrDisk($val);
        })->filter(function ($val) {
            return $val !== null;
        })->toArray();

        foreach ($settings as $setting) {
            $s = $setting[0];

            // is local file
            if (is_string($s)) {
                $from = $s;
                if (!\File::exists($from)) {
                    continue;
                }

                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting[1]);

                \Exment::makeDirectoryDisk($this->tmpDisk(), $to);

                \File::copyDirectory($from, $this->tmpDisk()->path($to));
            }
            // is croud file
            else {
                $disk = $setting[0];

                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting[1]);

                \Exment::makeDirectoryDisk($this->tmpDisk(), $to);

                $files = $disk->allFiles('');
                foreach ($files as $file) {
                    // Check file exists
                    if (!$disk->exists($file)) {
                        continue;
                    }
                    // copy from crowd to local
                    $stream = $disk->readStream($file);
                    $this->tmpDisk()->writeStream(path_join($to, $file), $stream);

                    try {
                        fclose($stream);
                    } catch (\Exception $ex) {
                    }
                }
            }
        }

        // if contains 'config' in $settings, copy env file
        if (collect($settings)->contains(function ($setting) {
            if (is_array($setting)) {
                return count($setting) >= 3 && $setting[2] == BackupTarget::CONFIG;
            }
            return $setting == BackupTarget::CONFIG;
        })) {
            $envLines = $this->getMatchedEnv();
            $to_env = $this->tmpDisk()->path(path_join($this->diskService->tmpDiskItem()->dirName(), '.env'));

            \File::put($to_env, $envLines);
        }

        return true;
    }

    /**
     * archive whole folder(sql and tsv only) to zip.
     *
     */
    protected function createZip()
    {
        // open new zip file
        $zip = new \ZipArchive();
        $res = $zip->open($this->diskService->tmpDiskItem()->fileFullPath(), \ZipArchive::CREATE);

        if ($res === true) {
            // iterator all files in folder
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->diskService->tmpDiskItem()->dirFullPath()));
            foreach ($files as $name => $file) {
                if ($file->isDir()) {
                    continue;
                }
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($this->diskService->tmpDiskItem()->dirFullPath()) + 1);
                $relativePath = \Exment::replaceBackToSlash($relativePath);
                $zip->addFile($filePath, $relativePath);
            }
            $zip->close();
        }

        // upload file

        $uploadPaths = [
            $this->diskService->tmpDiskItem()->filePath() => $this->diskService->diskItem()->filePath()
        ];

        $this->diskService->upload($uploadPaths);
    }

    /**
     * Remove old backup
     *
     * @return void
     */
    protected function removeOldBackups()
    {
        // get history file counts
        $backup_history_files = System::backup_history_files();
        if (is_nullorempty($backup_history_files) || $backup_history_files <= 0) {
            return;
        }

        $disk = $this->disk();

        // get files
        $filenames = $disk->files($this->diskService->diskItem()->dirName());

        // get file infos
        $files = collect($filenames)->map(function ($filename) use ($disk) {
            return [
                'name' => $filename,
                'lastModified' => $disk->lastModified($filename),
            ];
        })->sortByDesc('lastModified');

        // remove file
        foreach ($files->values()->all() as $index => $file) {
            if ($index < $backup_history_files) {
                continue;
            }

            $disk->delete(array_get($file, 'name'));
        }
    }

    /**
     * get matched env data
     *
     */
    protected function getMatchedEnv()
    {
        // get env file
        $file = path_join(base_path(), '.env');
        if (!\File::exists($file)) {
            return null;
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

        $ignoreKeys = ['EXMENT_COMPOSER_PATH', 'EXMENT_MYSQL_BIN_DIR'];

        $results = [];
        foreach ($matchKeys as $item) {
            foreach ($item['keys'] as $key) {
                if (is_null($lines = $this->getEnv($key, $file, $item['prefix']))) {
                    continue;
                }

                $results = array_merge(collect($lines)->filter(function ($line) use ($ignoreKeys) {
                    return !in_array($line[0], $ignoreKeys);
                })->map(function ($line) {
                    return "{$line[0]}={$line[1]}";
                })->toArray(), $results);
            }
        }

        return implode("\n", $results);
    }
}
