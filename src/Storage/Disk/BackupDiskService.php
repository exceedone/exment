<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\BackupRestore\RunProgress;
use Exceedone\Exment\Services\ZipService;
use Illuminate\Support\Facades\Storage;

class BackupDiskService extends DiskServiceBase
{
    /** The progress of the run of the restore (ISS02943): the download and the extraction. */
    public ?RunProgress $progress = null;

    /** The entries extracted at a time (the progress between them). */
    public const EXTRACT_CHUNK = 200;

    // @phpstan-ignore-next-line
    public function __construct(...$args)
    {
        $now = date('YmdHis');
        $fileName = isset($args[0]) ? $args[0] : $now;

        $this->diskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_BACKUP_SYNC), "$fileName.zip", 'list');
        $this->tmpDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_ADMIN_TMP), "$now.zip", $now);
        $this->localSyncDiskItem = $this->tmpDiskItem;
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

        if ($this->diskItem()->isDriverLocal()) {
            return false;
        }

        return true;
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
     * copy file from disk to localSyncDisk disk
     *
     * @return true
     */
    protected function sync()
    {
        ///// copy to sync disk
        $diskItem = $this->diskItem();
        $localSyncDiskItem = $this->localSyncDiskItem();

        $disk = $diskItem->disk();
        $localSyncDisk = $localSyncDiskItem->disk();

        \Exment::makeDirectoryDisk($localSyncDisk, $localSyncDiskItem->dirName());

        // a backup on this server is read where it is; else it is downloaded first (ISS02943:
        // a copy of the whole file less)
        $zipPath = $this->localZipPath();
        $downloaded = false;
        if ($zipPath === null) {
            // download zip
            $stream = $disk->readStream($diskItem->filePath());
            $localSyncDisk->writeStream($localSyncDiskItem->filePath(), $stream);
            try {
                fclose($stream);
            } catch (\Exception $ex) {
            }
            $zipPath = $localSyncDiskItem->fileFullPath();
            $downloaded = true;
        }
        $this->progress?->info('zip', ['bytes' => intval(@filesize($zipPath)), 'downloaded' => $downloaded]);

        // open new zip file
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            // an unreadable stored backup must not look like a successful restore:
            // the caller would keep going over an empty folder, restore nothing,
            // and still answer "restore succeeded". same rule as Restore::unzipFile().
            throw new InvalidZipEntryException(strval(exmtrans('backup.message.restore_file_error')));
        }

        $zipEntryError = ZipService::validateZipEntries($zip);
        if ($zipEntryError !== null) {
            $zip->close();
            throw new InvalidZipEntryException($zipEntryError);
        }

        static::verify($zip, $zipPath, $this->expectedSha256(), $this->progress);
        static::extract($zip, $localSyncDiskItem->dirFullPath(), $this->progress);
        $zip->close();

        if ($downloaded) {
            $localSyncDisk->delete($localSyncDiskItem->filePath());
        }

        return true;
    }

    /**
     * Check a backup before it is extracted (ISS02943): the sha256 of the file against the one
     * recorded when it was made (when it is known), then every entry read to its end, so a file
     * cut short or broken (its CRC) is found before the database is replaced.
     *
     * @throws InvalidZipEntryException
     */
    public static function verify(\ZipArchive $zip, string $zipPath, ?string $expectedSha256, ?RunProgress $progress = null): void
    {
        if (!boolval(config('exment.backup.verify', true))) {
            return;
        }
        $sha256 = hash_file('sha256', $zipPath);
        if (isset($expectedSha256) && $sha256 !== false && !hash_equals($expectedSha256, $sha256)) {
            $progress?->error('the sha256 of the backup file differs from when it was made', ['expected' => $expectedSha256, 'actual' => $sha256]);
            throw new InvalidZipEntryException(strval(exmtrans('backup.run.message.verify_sha256')));
        }
        $progress?->info('sha256', ['sha256' => $sha256 === false ? null : $sha256, 'recorded' => isset($expectedSha256)]);

        $progress?->total($zip->numFiles);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = strval($zip->getNameIndex($i));
            if (str_ends_with($name, '/')) {
                $progress?->advance(1);
                continue;
            }
            if (!static::readsToTheEnd($zip, $i)) {
                $progress?->error('a file of the backup cannot be read', ['entry' => $name]);
                throw new InvalidZipEntryException(strval(exmtrans('backup.run.message.verify_read', ['name' => $name])));
            }
            $progress?->advance(1);
        }
    }

    /**
     * Read an entry to its end: false when it cannot be, or when what was read is not what the
     * zip recorded (its size and its CRC).
     */
    protected static function readsToTheEnd(\ZipArchive $zip, int $index): bool
    {
        $failed = false;
        set_error_handler(function () use (&$failed) {
            $failed = true;
            return true;
        });
        try {
            $stat = $zip->statIndex($index);
            $stream = $zip->getStreamIndex($index);
            if ($stat === false || $stream === false) {
                return false;
            }
            $crc = hash_init('crc32b');
            $size = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) {
                    $failed = true;
                    break;
                }
                hash_update($crc, $chunk);
                $size += strlen($chunk);
            }
            fclose($stream);
            if ($failed || $size !== intval($stat['size'])) {
                return false;
            }
            return intval(hexdec(hash_final($crc))) === intval($stat['crc']);
        } catch (\Throwable $e) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The sha256 recorded when the stored backup was made (null: not known, e.g. made before
     * it was recorded, or renamed).
     */
    protected function expectedSha256(): ?string
    {
        try {
            $name = $this->diskItem()->fileNameNoExtension();
            $sha = BackupRun::query()->where('kind', BackupRun::KIND_BACKUP)
                ->where('file_name', $name)
                ->whereIn('status', [BackupRun::STATUS_SUCCEEDED, BackupRun::STATUS_WARNING])
                ->whereNotNull('sha256')
                ->orderByDesc('id')
                ->value('sha256');
            return is_string($sha) && strlen($sha) === 64 ? $sha : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The path of the backup file when it is a file of this server (the local disk), else null.
     */
    protected function localZipPath(): ?string
    {
        try {
            $path = $this->diskItem()->disk()->path($this->diskItem()->filePath());
        } catch (\Throwable $e) {
            return null;
        }
        if ($path === '' || !(str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path))) {
            return null;
        }
        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * Extract a zip checked already (ZipService::validateZipEntries), some entries at a time so
     * the progress moves (the stage "extract").
     */
    public static function extract(\ZipArchive $zip, string $dir, ?RunProgress $progress = null): void
    {
        if (!isset($progress)) {
            $zip->extractTo($dir);
            return;
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        $progress->stage('extract', count($names));
        foreach (array_chunk($names, static::EXTRACT_CHUNK) as $chunk) {
            if (!$zip->extractTo($dir, $chunk)) {
                throw new \RuntimeException('the backup file could not be extracted: ' . $zip->getStatusString());
            }
            $progress->advance(count($chunk), count($chunk));
        }
    }
}
