<?php

namespace Exceedone\Exment\Services\DataArchive;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Storage;

/**
 * Where the files of the data archive are (ISS02970):
 * - local: storage/app/data_archive, readable by the owner only (600; folders 700)
 * - backup: the disk of the backups (the server, or S3 with EXMENT_DRIVER_BACKUP)
 *
 * A file is written next to the server first, then put in its place. To check it, it is read
 * from its place again (from S3 too), never from the copy that was written.
 */
class ArchiveStore
{
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_BACKUP = 'backup';
    public const DRIVERS = [self::DRIVER_LOCAL, self::DRIVER_BACKUP];

    public const ROOT = 'data_archive';

    public static function driver(): string
    {
        try {
            $driver = strval(System::data_archive_driver());
        } catch (\Throwable $e) {
            $driver = '';
        }
        return in_array($driver, static::DRIVERS, true) ? $driver : static::DRIVER_LOCAL;
    }

    /** The path of a new archive in its place. */
    public static function pathOf(string $source, string $uuid): string
    {
        return static::ROOT . '/' . preg_replace('/[^a-z0-9_]/', '_', $source) . '/' . $uuid . '.zip';
    }

    /** A folder for the work of a run (removed by the caller). */
    public static function workDir(): string
    {
        $dir = storage_path('app/' . static::ROOT . '/.work/' . bin2hex(random_bytes(8)));
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * Put a file written next to the server in its place.
     */
    public static function put(string $driver, string $local, string $path): void
    {
        if ($driver === static::DRIVER_LOCAL) {
            $full = storage_path('app/' . $path);
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0700, true);
            }
            if (!@copy($local, $full)) {
                throw new \RuntimeException('the archive could not be written: ' . $path);
            }
            @chmod($full, 0600);
            return;
        }
        $stream = fopen($local, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('the archive could not be read: ' . $local);
        }
        try {
            if (!static::disk()->writeStream($path, $stream)) {
                throw new \RuntimeException('the archive could not be written: ' . $path);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A copy of a file from its place, next to the server, to read it (from S3: downloaded).
     */
    public static function fetch(string $driver, string $path, string $to): bool
    {
        if ($driver === static::DRIVER_LOCAL) {
            $full = storage_path('app/' . $path);
            return is_file($full) && @copy($full, $to);
        }
        if (!static::disk()->exists($path)) {
            return false;
        }
        $in = static::disk()->readStream($path);
        $out = fopen($to, 'wb');
        if (!is_resource($in) || $out === false) {
            return false;
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        return true;
    }

    public static function exists(string $driver, string $path): bool
    {
        return $driver === static::DRIVER_LOCAL ? is_file(storage_path('app/' . $path)) : static::disk()->exists($path);
    }

    public static function delete(string $driver, string $path): void
    {
        if ($driver === static::DRIVER_LOCAL) {
            @unlink(storage_path('app/' . $path));
            return;
        }
        static::disk()->delete($path);
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected static function disk()
    {
        return Storage::disk(Define::DISKNAME_BACKUP);
    }

    /** Remove a folder of work and what is in it. */
    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? static::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
