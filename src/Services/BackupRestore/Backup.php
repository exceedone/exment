<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
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
    // @phpstan-ignore-next-line
    public function execute($target = null, bool $schedule = false)
    {
        $progress = $this->progress();
        try {
            $progress->stage('prepare');

            // check backup execute
            \ExmentDB::checkBackup();

            $target = $target ?? BackupTarget::arrays();

            if (is_string($target)) {
                $target = collect(explode(",", $target))->map(function ($t) {
                    // @phpstan-ignore-next-line
                    return new BackupTarget($t) ?? null;
                })->filter()->toArray();
            }

            $this->initBackupRestore($progress->run()?->file_name);
            $this->recordFileName();
            $progress->info('targets', ['targets' => implode(',', array_map('strval', (array) $target)), 'free_bytes' => static::freeBytes($this->diskService->tmpDiskItem()->dirFullPath())]);

            // backup database tables
            $progress->stage('dump');
            if (in_array(BackupTarget::DATABASE, $target)) {
                $dir = $this->diskService->tmpDiskItem()->dirFullPath();
                \Exment::makeDirectoryDisk($this->tmpDisk(), $this->diskService->tmpDiskItem()->dirName());
                \ExmentDB::backupDatabase($dir, function (array $info) use ($progress) {
                    $this->databaseProgress($progress, $info);
                });
            } else {
                $progress->info('skipped: not a target');
            }

            // backup directory
            $progress->stage('files');
            if (!$this->copyFiles($target)) {
                return -1;
            }

            // record the versions this backup is taken on
            $this->writeVersionFile();

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
            $this->diskService?->deleteTmpDirectory();
        }
    }

    /**
     * The name of the backup file in the run (the screen and the history show it).
     */
    protected function recordFileName(): void
    {
        $run = $this->progress()->run();
        if (isset($run)) {
            $run->file_name = strval($this->diskService->diskItem()->fileNameNoExtension());
        }
    }

    /**
     * A table of the database is written (ExmentDB::backupDatabase).
     *
     * @param array<string, mixed> $info
     */
    protected function databaseProgress(RunProgress $progress, array $info): void
    {
        if (isset($info['total'])) {
            $progress->total(intval($info['total']));
        }
        if (boolval($info['start'] ?? false)) {
            $progress->info('tables', ['count' => intval($info['total'] ?? 0)]);
            return;
        }
        $path = $info['path'] ?? null;
        $bytes = is_string($path) && is_file($path) ? intval(filesize($path)) : 0;
        $table = $info['table'] ?? '(definition)';
        $exitCode = intval($info['exit_code'] ?? 0);
        if ($exitCode !== 0) {
            $progress->error('the dump of a table failed', ['table' => $table, 'exit_code' => $exitCode]);
        }
        $progress->info('table', array_filter(['table' => $table, 'rows' => $info['rows'] ?? null, 'bytes' => $bytes], fn ($v) => $v !== null));
        $progress->advance(1, 1, $bytes);
    }

    /**
     * The free bytes of the disk of a folder (null: not known).
     */
    public static function freeBytes(string $dir): ?int
    {
        $path = $dir;
        while (!is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }
        $free = @disk_free_space($path);
        return $free === false ? null : intval($free);
    }

    /**
     * copy folder to temp directory
     *
     * @return bool true:success/false:fail
     */
    // @phpstan-ignore-next-line
    protected function copyFiles($target)
    {
        // get directory paths
        $settings = collect($target)->map(function ($val) {
            return BackupTarget::dirOrDisk($val);
        })->filter(function ($val) {
            return $val !== null;
        })->toArray();

        $progress = $this->progress();

        // the files first: the total of the stage
        $lists = [];
        $total = 0;
        foreach ($settings as $index => $setting) {
            $s = $setting[0];
            if (is_string($s)) {
                $lists[$index] = \File::exists($s) ? \File::allFiles($s, true) : [];
            } else {
                $lists[$index] = $s->allFiles('');
            }
            $total += count($lists[$index]);
        }
        $progress->total($total);

        foreach ($settings as $index => $setting) {
            $s = $setting[0];
            $count = 0;
            $bytes = 0;

            // is local file
            if (is_string($s)) {
                $from = $s;
                if (!\File::exists($from)) {
                    $progress->info('folder not found, skipped', ['target' => $setting[2] ?? $setting[1]]);
                    continue;
                }

                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting[1]);

                \Exment::makeDirectoryDisk($this->tmpDisk(), $to);

                // as File::copyDirectory, a file at a time (the progress), the empty folders too
                $toFull = $this->tmpDisk()->path($to);
                foreach (\File::allDirectories($from) as $directory) {
                    \File::ensureDirectoryExists(path_join($toFull, ltrim(substr($directory, strlen($from)), '\\/')));
                }
                foreach ($lists[$index] as $file) {
                    /** @var \Symfony\Component\Finder\SplFileInfo $file */
                    $dest = path_join($toFull, $file->getRelativePathname());
                    \File::ensureDirectoryExists(dirname($dest));
                    $size = intval($file->getSize());
                    if (!@copy($file->getPathname(), $dest)) {
                        $progress->warn('a file was not copied', ['target' => $setting[2] ?? $setting[1], 'file' => $file->getRelativePathname()]);
                        $progress->advance(1);
                        continue;
                    }
                    $count++;
                    $bytes += $size;
                    $progress->advance(1, 1, $size);
                }
            }
            // is croud file
            else {
                $disk = $setting[0];

                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting[1]);

                \Exment::makeDirectoryDisk($this->tmpDisk(), $to);

                foreach ($lists[$index] as $file) {
                    // Check file exists
                    if (!$disk->exists($file)) {
                        $progress->advance(1);
                        continue;
                    }
                    // copy from crowd to local
                    $stream = $disk->readStream($file);
                    $this->tmpDisk()->writeStream(path_join($to, $file), $stream);

                    try {
                        fclose($stream);
                    } catch (\Exception $ex) {
                    }
                    $size = intval($this->tmpDisk()->size(path_join($to, $file)));
                    $count++;
                    $bytes += $size;
                    $progress->advance(1, 1, $size);
                }
            }
            $progress->info('folder', ['target' => $setting[2] ?? $setting[1], 'files' => $count, 'bytes' => $bytes]);
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
     * Write the Exment and Laravel versions to the backup.
     *
     * Restore reads it to tell whether the backup's config files fit this installation:
     * config files of another Laravel version must not overwrite the current ones.
     *
     * @return void
     */
    protected function writeVersionFile()
    {
        $dirName = $this->diskService->tmpDiskItem()->dirName();
        \Exment::makeDirectoryDisk($this->tmpDisk(), $dirName);

        \File::put($this->tmpDisk()->path(path_join($dirName, Define::BACKUP_VERSION_FILE)), json_encode([
            'exment' => \Exment::getExmentCurrentVersion(),
            'laravel' => app()->version(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * archive whole folder(sql and tsv only) to zip.
     *
     */
    // @phpstan-ignore-next-line
    protected function createZip()
    {
        $progress = $this->progress();
        $progress->stage('archive', 100);

        // open new zip file
        $zip = new \ZipArchive();
        $res = $zip->open($this->diskService->tmpDiskItem()->fileFullPath(), \ZipArchive::CREATE);

        if ($res === true) {
            $count = 0;
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
                $count++;
            }
            // the files are compressed when the zip is closed: its progress (0 to 1) as percent
            $zip->registerProgressCallback(0.02, function ($rate) use ($progress) {
                $done = intval(round(floatval($rate) * 100));
                $run = $progress->run();
                if (isset($run) && $done > $run->stage_done) {
                    $progress->advance($done - $run->stage_done);
                }
            });
            if (!$zip->close()) {
                throw new \RuntimeException('the zip file could not be written: ' . $zip->getStatusString());
            }
            $size = intval(@filesize($this->diskService->tmpDiskItem()->fileFullPath()));
            $progress->info('zip', ['files' => $count, 'bytes' => $size]);
            $run = $progress->run();
            if (isset($run)) {
                $run->file_size = $size;
            }
        } else {
            throw new \RuntimeException('the zip file could not be made: ' . strval($res));
        }

        // upload file
        $progress->stage('upload', 1);

        $uploadPaths = [
            $this->diskService->tmpDiskItem()->filePath() => $this->diskService->diskItem()->filePath()
        ];

        $this->diskService->upload($uploadPaths);
        $progress->advance(1, 1, intval($progress->run()?->file_size));
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
    // @phpstan-ignore-next-line
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

        // never DATA_ENCRYPTION_KEYS (not matched above): a backup alone must not read the
        // encrypted columns (ISS00148, Services\Encryption\DataKeys)
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
