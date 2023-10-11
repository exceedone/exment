<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use File;

class Restore
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
     * Get restore target list
     *
     * @return array
     */
    public function list(): array
    {
        $disk = $this->disk();

        // get all archive files
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
    public function execute($file = null, ?bool $tmp = null)
    {
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

        return $result;
    }

    /**
     * update env data
     *
     */
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
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($this->diskService->tmpDiskItem()->dirFullPath());
                $zip->close();
            }
        }

        return true;
    }


    /**
     * restore backup table definition and table data.
     *
     */
    protected function restoreDatabase()
    {
        \ExmentDB::restoreDatabase($this->diskService->tmpDiskItem()->dirFullPath());
    }
}
