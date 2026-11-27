<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Services\CoreExtensions;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Services\ZipService;
use Exceedone\Exment\Storage\Disk\BackupDiskService;
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
        $progress = $this->progress();

        try {
            $progress->stage('verify');
            // the page of the maintenance tells a restore is going on (and comes again by itself);
            // the pages of the backup still answer (BackupRestoreGuard, before the maintenance): the
            // page of "down --render" is given before Laravel starts, but not for its exceptions
            try {
                static::passBackupPagesThroughMaintenance(function () {
                    \Artisan::call('down', static::downOptions());
                });
            } catch (\Throwable $e) {
                // the page could not be made: the plain one
                static::passBackupPagesThroughMaintenance(function () {
                    \Artisan::call('down');
                });
            }

            // check backup execute
            \ExmentDB::checkBackup();

            // unzip backup file (the stage "extract" starts when the file is checked)
            $this->unzipFile($file, $tmp);

            $result = 0;

            $progress->stage('load', $this->countDatabaseFiles());

            // the rules of an application before the database is replaced (CoreExtensions::addRestoreRule)
            $this->runRestoreRules('before');

            // the runs of the backup are kept, and put back when the database is replaced
            $progress->databaseReplacing();

            // restore table definition
            $this->restoreDatabase();

            // import tsv file to table
            $this->importTsv();

            // copy directory to temporary folder
            $progress->stage('files');
            if (!$this->copyFiles()) {
                $progress->error('some folders were not copied');
                $result = -1;
            }

            // copy env
            $progress->stage('finish', 2);
            $this->updateEnv();

            $this->runRestoreRules('after');

            // Everything cached so far describes rows that no longer exist, and the migrations
            // below read Exment's own models - clear before, and again after, so the caller
            // starts from a clean cache whichever way the migration step goes.
            System::clearCache();
            $progress->advance(1);

            if (!$this->migrateToCurrentSchema()) {
                $progress->error('migrate failed after the database was restored: the schema is still at the version of the backup');
                $result = -1;
            }

            System::clearCache();

            $progress->databaseReplaced();
            $progress->advance(1);

            return $result;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            // a restore that stopped half way: the record is written again all the same
            $progress->databaseReplaced();
            \Artisan::call('up');
            $this->diskService?->deleteTmpDirectory();
        }
    }


    /**
     * What a restore of a stored backup replaces (ISS02943), read from its entries without
     * extracting it: the database (its tables), the folders (their files and size), the
     * settings, and the versions it was made on. Null when the file cannot be read here (a
     * backup on another disk is not downloaded only for this).
     *
     * @return array<string, mixed>|null
     */
    public function preview(string $fileKey): ?array
    {
        if (!preg_match('/^' . Define::RULES_REGEX_BACKUP_FILENAME . '$/u', $fileKey)) {
            return null;
        }
        $this->initBackupRestore($fileKey);
        try {
            $path = $this->disk()->path($this->diskService->diskItem()->filePath());
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_file($path)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            $tables = 0;
            $hasDefinition = false;
            $folders = [];
            $env = false;
            $versions = null;
            $def = strval(config('exment.backup_info.def_file', 'table_definition.sql'));
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = strval($stat['name']);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (!str_contains($name, '/')) {
                    if ($name === $def) {
                        $hasDefinition = true;
                    } elseif (preg_match('/\.(sql|tsv)$/i', $name)) {
                        $tables++;
                    } elseif ($name === '.env') {
                        $env = true;
                    } elseif ($name === Define::BACKUP_VERSION_FILE) {
                        $versions = json_decode_ex(strval($zip->getFromIndex($i)), true);
                    }
                    continue;
                }
                $setting = BackupTarget::dirOrDisk(explode('/', '/' . $name));
                $key = isset($setting) ? strval($setting[2]) : strval(explode('/', $name)[0]);
                $folders[$key] = [
                    'files' => intval($folders[$key]['files'] ?? 0) + 1,
                    'bytes' => intval($folders[$key]['bytes'] ?? 0) + intval($stat['size']),
                ];
            }
            return [
                'database' => $hasDefinition || $tables > 0,
                'tables' => $tables,
                'folders' => $folders,
                'env' => $env,
                'exment' => is_array($versions) ? array_get($versions, 'exment') : null,
                'laravel' => is_array($versions) ? array_get($versions, 'laravel') : null,
                'current_exment' => \Exment::getExmentCurrentVersion(),
                'current_laravel' => app()->version(),
                'bytes' => intval(@filesize($path)),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Run the "down" of a restore with the pages of the backup among its exceptions (written in
     * the file of the maintenance): those requests reach Laravel, where BackupRestoreGuard answers
     * them while the restore goes on (or the maintenance answers 503). Only this "down": an
     * ordinary one has no exception.
     */
    protected static function passBackupPagesThroughMaintenance(\Closure $down): void
    {
        $middleware = \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class;
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $base = $prefix === '' ? '' : $prefix . '/';
        $middleware::except([$base . 'backup', $base . 'backup/runs/*']);
        try {
            $down();
        } finally {
            $middleware::flushState();
        }
    }

    /**
     * The options of "down" during a restore (ISS02943): the page "a restore is going on".
     *
     * @return array<string, mixed>
     */
    public static function downOptions(): array
    {
        return ['--render' => 'exment::backup.restoring', '--retry' => 30, '--refresh' => 15];
    }

    /**
     * Bring the restored schema up to the version the running code expects.
     *
     * restoreDatabase() replays the backup's table definitions, so after a restore the schema
     * is whatever it was on the day of the backup while the application code is still current.
     * Every column, index and table added since then is gone, and the code that needs them
     * fails at runtime - often quietly, because callers tend to catch the SQL error and skip
     * the feature rather than stop. That is not a restore succeeding; it is a restore leaving
     * the tenant broken.
     *
     * The restore also rolls back the migrations table (backupDatabase() dumps every table
     * \Schema::getTableListing() reports, that one included), which is what makes a plain
     * `migrate` the right repair and not a heavy-handed one: it replays exactly the migrations
     * whose record the restore removed, and nothing else. A backup taken after a migration
     * still carries that migration's record, so the migration is skipped and whatever the
     * tenant did afterwards - including deleting something the migration had created - is
     * preserved. Restoring stays faithful to the backup.
     *
     * Failure is reported, never swallowed: the caller turns it into a non-zero exit code so
     * the operator finds out now, rather than when a user opens the page that needs the
     * missing column.
     *
     * @return bool true when the schema is in sync with the code afterwards
     */
    protected function migrateToCurrentSchema(): bool
    {
        try {
            // --force: this runs unattended (queue worker, cron applier, CLI restore), and
            // migrate refuses to touch a production database without it.
            $exitCode = \Artisan::call('migrate', ['--force' => true]);
            $output = \Artisan::output();

            if ($exitCode !== 0) {
                \Log::error('Restore: migrate failed after restoring the database. The schema is still at the version of the backup.', [
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);
                return false;
            }

            \Log::info('Restore: migrate finished after restoring the database.', ['output' => $output]);
            return true;
        } catch (\Throwable $e) {
            // Console commands run with catchExceptions disabled, so a migration that throws
            // lands here. Do not rethrow: the data restore itself did succeed, and the finally
            // block of execute() still has to bring the application back up.
            \Log::error('Restore: migrate threw after restoring the database. The schema is still at the version of the backup.', [
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * insert table data from backup tsv files.
     *
     */
    // @phpstan-ignore-next-line
    protected function importTsv()
    {
        \ExmentDB::importTsv($this->diskService->tmpDiskItem()->dirFullPath(), function (array $info) {
            $this->databaseProgress($info);
        });
    }

    /**
     * The files of the database in the backup (the units of the stage "database").
     */
    protected function countDatabaseFiles(): int
    {
        $dir = $this->diskService->tmpDiskItem()->dirFullPath();
        if (!\File::isDirectory($dir)) {
            return 0;
        }
        return count(array_filter(\File::files($dir), function ($file) {
            return preg_match('/.+\.(sql|tsv)$/i', $file->getFilename()) && !preg_match('/^view_.*/i', $file->getFilename());
        }));
    }

    /**
     * A file of the database is restored (ExmentDB::restoreDatabase / importTsv).
     *
     * @param array<string, mixed> $info
     */
    protected function databaseProgress(array $info): void
    {
        $progress = $this->progress();
        $table = $info['table'] ?? '(definition)';
        $exitCode = intval($info['exit_code'] ?? 0);
        if ($exitCode !== 0) {
            $progress->error('a file of the database was not restored', ['table' => $table, 'exit_code' => $exitCode]);
        }
        $progress->info('table', array_filter(['table' => $table, 'rows' => $info['rows'] ?? null, 'bytes' => $info['bytes'] ?? null], fn ($v) => $v !== null));
        $progress->advance(1, 1, intval($info['bytes'] ?? 0));
        $progress->heartbeat();
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
        $progress = $this->progress();
        $progress->total(count($directories));
        // a folder of this server is copied whole once (it was copied again for each of its
        // folders, ISS02943)
        $copiedLocal = [];
        // the files of a disk (attachments, plugins): written and the same, for each target
        $diskCounts = [];

        foreach ($directories as $directory) {
            $progress->advance(1);
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
            if (!$this->restoresFile($setting[2], null)) {
                continue;
            }

            // config files of another Laravel version would overwrite the current ones and
            // stop the application from starting (e.g. config/app.php listing providers
            // a Laravel 12 skeleton no longer has)
            // the logs of now are kept: what this restore logs, and what came before it
            if ($setting[2] == BackupTarget::LOG) {
                if (!isset($logsKept)) {
                    $logsKept = true;
                    $progress->info('the log files of now are kept (not replaced)');
                }
                continue;
            }

            if ($setting[2] == BackupTarget::CONFIG && !$this->canRestoreConfig()) {
                $this->configSkipped = true;
                continue;
            }

            $fromDirectory = $tmpDisk->path(path_join($this->diskService->tmpDiskItem()->dirName(), $keyname));

            $s = $setting[0];
            // is local file
            if (is_string($s)) {
                $topath = $s;

                // In a multi-tenant install the framework directories (config, routes,
                // vendor) are symlinks into a shared mount that is deliberately read-only:
                // writing there is impossible, and would overwrite the files of every other
                // tenant on the same pool. Skip such a target instead of letting copy() raise
                // and abort the whole restore - otherwise restore can never succeed there.
                if (!$this->isWritableDirectory($topath)) {
                    \Log::warning('Restore: skipped a directory that is not writable.', [
                        'path' => $topath,
                        'target' => $setting[2] ?? $keyname,
                    ]);
                    $progress->warn('a folder that is not writable was skipped', ['target' => $setting[2] ?? $keyname]);
                    continue;
                }

                if (isset($copiedLocal[$keyname])) {
                    continue;
                }
                $copiedLocal[$keyname] = true;

                [$success, $copied, $same] = $this->copyLocalDirectory($fromDirectory, $topath);
                if (!$success) {
                    $progress->error('a folder was not copied', ['target' => $setting[2] ?? $keyname]);
                    $result = false;
                } else {
                    $progress->info('folder', ['target' => $setting[2] ?? $keyname, 'copied' => $copied, 'unchanged' => $same]);
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
                    if (!$this->restoresFile($setting[2], $path)) {
                        continue;
                    }
                    $target = strval($setting[2]);
                    $diskCounts[$target] = $diskCounts[$target] ?? ['copied' => 0, 'unchanged' => 0];
                    // the same file is there already: not written again (ISS02943)
                    if ($this->isSameOnDisk($disk, $path, $tmpDisk->path($file))) {
                        $diskCounts[$target]['unchanged']++;
                        continue;
                    }
                    $diskCounts[$target]['copied']++;
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

        foreach ($diskCounts as $target => $counts) {
            $progress->info('folder', ['target' => $target, 'copied' => $counts['copied'], 'unchanged' => $counts['unchanged']]);
        }

        if ($this->configSkipped) {
            \Log::warning('Exment: restore skipped the config files of the backup: ' . $this->getBackupLaravelVersionText());
            $progress->info('config skipped', ['versions' => $this->getBackupLaravelVersionText()]);
        }

        return $result;
    }

    /**
     * Copy a folder of the backup over a folder of this server, as File::copyDirectory, without
     * writing again a file that is the same already (its size and its sha1) (ISS02943).
     *
     * @return array{0: bool, 1: int, 2: int} done, the files written, the files the same
     */
    protected function copyLocalDirectory(string $from, string $to): array
    {
        if (!\File::isDirectory($from)) {
            return [false, 0, 0];
        }
        $skip = boolval(config('exment.backup.skip_unchanged', true));
        $copied = 0;
        $same = 0;
        try {
            \File::ensureDirectoryExists($to);
            foreach (\File::allDirectories($from) as $directory) {
                \File::ensureDirectoryExists(path_join($to, ltrim(substr($directory, strlen($from)), '\\/')));
            }
            foreach (\File::allFiles($from, true) as $file) {
                $dest = path_join($to, $file->getRelativePathname());
                if ($skip && static::isSameFile($file->getPathname(), $dest)) {
                    $same++;
                    continue;
                }
                \File::ensureDirectoryExists(dirname($dest));
                if (!@copy($file->getPathname(), $dest)) {
                    return [false, $copied, $same];
                }
                $copied++;
            }
        } catch (\Throwable $e) {
            return [false, $copied, $same];
        }
        return [true, $copied, $same];
    }

    /**
     * Whether two files of this server are the same (their size, then their sha1).
     */
    public static function isSameFile(string $a, string $b): bool
    {
        if (!is_file($a) || !is_file($b) || filesize($a) !== filesize($b)) {
            return false;
        }
        $hashA = hash_file('sha1', $a);
        return $hashA !== false && $hashA === hash_file('sha1', $b);
    }

    /**
     * Whether the file of a disk is the same as the file of the backup (its size, then its md5).
     *
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     */
    protected function isSameOnDisk($disk, string $path, string $local): bool
    {
        if (!boolval(config('exment.backup.skip_unchanged', true))) {
            return false;
        }
        try {
            if (!is_file($local) || !$disk->exists($path) || intval($disk->size($path)) !== intval(filesize($local))) {
                return false;
            }
            if (!method_exists($disk, 'checksum')) {
                return false;
            }
            $checksum = $disk->checksum($path, ['checksum_algo' => 'md5']);
            return is_string($checksum) && $checksum !== '' && hash_equals(strval(md5_file($local)), $checksum);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether a local directory from the backup can actually be written to.
     *
     * Returns false when the path lives on a read-only mount, which is how shared framework
     * code is exposed to each tenant in a multi-tenant install.
     *
     * @param string $path
     * @return bool
     */
    /**
     * Whether the value of $key in the backup's .env is written (the rules of an application).
     */
    protected function restoresEnv(string $key): bool
    {
        foreach (CoreExtensions::restoreRules('env') as $rule) {
            if (!$rule($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a folder of the backup ($path null) or a file in it is restored (the rules of an
     * application).
     */
    protected function restoresFile(string $target, ?string $path): bool
    {
        foreach (CoreExtensions::restoreRules('file') as $rule) {
            if (!$rule($target, $path)) {
                return false;
            }
        }
        return true;
    }

    protected function runRestoreRules(string $kind): void
    {
        foreach (CoreExtensions::restoreRules($kind) as $rule) {
            $rule();
        }
    }

    protected function isWritableDirectory(string $path): bool
    {
        if (\File::isDirectory($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);

        return \File::isDirectory($parent) && is_writable($parent);
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

        // Never import keys that control external binary paths from a restored .env:
        // a crafted backup could point them at attacker payloads (command injection).
        // Mirrors the exclusion list used when creating a backup (Backup::getMatchedEnv).
        $ignoreKeys = ['EXMENT_COMPOSER_PATH', 'EXMENT_MYSQL_BIN_DIR'];

        foreach ($matchKeys as $item) {
            foreach ($item['keys'] as $key) {
                if (is_null($lines = $this->getEnv($key, $file, $item['prefix']))) {
                    continue;
                }

                foreach ($lines as $line) {
                    if (in_array($line[0], $ignoreKeys, true) || !$this->restoresEnv($line[0])) {
                        continue;
                    }
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
        $this->diskService->progress = $this->progress();
        $run = $this->progress()->run();
        if (isset($run) && !isset($run->file_name)) {
            $run->file_name = mb_substr(strval($targetfile), 0, 100);
        }

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

            $this->progress()->info('zip', ['files' => $zip->numFiles, 'bytes' => intval(@filesize($zipPath))]);
            BackupDiskService::verify($zip, $zipPath, null, $this->progress());
            BackupDiskService::extract($zip, $this->diskService->tmpDiskItem()->dirFullPath(), $this->progress());
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
        \ExmentDB::restoreDatabase($this->diskService->tmpDiskItem()->dirFullPath(), function (array $info) {
            $this->databaseProgress($info);
        });
    }
}
