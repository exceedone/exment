<?php

namespace Exceedone\Exment\Services\LogManagement;

use Carbon\Carbon;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Storage;

/**
 * The archive of the logs (ISS02839, part 3): before the old logs are deleted, they are put in one
 * gzip file a month and a kind (log_archive/<kind>/<kind>-YYYY-MM.csv.gz, or .log.gz for the files
 * of the application log). A month gets more as it is deleted: the file grows by gzip members,
 * which any gzip reads as one.
 *
 * Where (log_archive_driver):
 * - local: storage/app/log_archive, readable by the owner only (600; folders 700)
 * - backup: where the backups go (the disk "backup": the server, or S3 with EXMENT_DRIVER_BACKUP)
 *
 * The archives hold personal data: only the system administrators download them. They are kept
 * log_archive_years years (empty: kept until deleted).
 */
class LogArchiver
{
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_BACKUP = 'backup';
    public const DRIVERS = [self::DRIVER_LOCAL, self::DRIVER_BACKUP];

    public const ROOT = 'log_archive';

    public static function enabled(): bool
    {
        try {
            return boolval(System::log_archive_enabled());
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function driver(): string
    {
        try {
            $driver = strval(System::log_archive_driver());
        } catch (\Throwable $e) {
            $driver = '';
        }
        return in_array($driver, static::DRIVERS, true) ? $driver : static::DRIVER_LOCAL;
    }

    public static function years(): ?int
    {
        try {
            $years = System::log_archive_years();
        } catch (\Throwable $e) {
            return null;
        }
        return is_numeric($years) && intval($years) > 0 ? intval($years) : null;
    }

    /**
     * Put rows of a kind in the archive, each in the file of the month of its date.
     *
     * @param array<int, string> $header
     * @param iterable<array<int, mixed>> $rows
     * @param \Closure $monthOf function (array $row): string, "YYYY-MM"
     */
    public static function rows(string $kind, array $header, iterable $rows, \Closure $monthOf): int
    {
        $byMonth = [];
        $count = 0;
        foreach ($rows as $row) {
            $month = strval($monthOf($row));
            $byMonth[$month][] = $row;
            $count++;
        }
        foreach ($byMonth as $month => $monthRows) {
            $path = static::path($kind, $month, 'csv');
            $csv = static::exists($path) ? '' : static::csvLine($header);
            foreach ($monthRows as $row) {
                $csv .= static::csvLine($row);
            }
            static::append($path, strval(gzencode($csv, 6)));
        }
        return $count;
    }

    /**
     * Put a file (a daily file of the application log) in the archive of its month.
     */
    public static function file(string $kind, string $month, string $file): bool
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }
        static::append(static::path($kind, $month, 'log'), strval(gzencode("==> " . basename($file) . " <==\n" . $content, 6)));
        return true;
    }

    /**
     * The archive files: path => [kind, month, size].
     *
     * @return array<string, array{kind: string, month: string, size: int}>
     */
    public static function files(): array
    {
        $files = [];
        foreach (static::listPaths() as $path) {
            if (preg_match('#^' . static::ROOT . '/([a-z0-9_]+)/\1-(\d{4}-\d{2})\.(csv|log)\.gz$#', $path, $m) !== 1) {
                continue;
            }
            $files[$path] = ['kind' => $m[1], 'month' => $m[2], 'size' => static::size($path)];
        }
        krsort($files);
        return $files;
    }

    /**
     * Delete the archives older than they are kept.
     */
    public static function prune(?Carbon $now = null): int
    {
        $years = static::years();
        if (!isset($years)) {
            return 0;
        }
        $before = ($now ?? Carbon::now())->copy()->subYears($years)->format('Y-m');
        $deleted = 0;
        foreach (static::files() as $path => $file) {
            if ($file['month'] < $before && static::delete($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Whether $path is an archive file (for the download). */
    public static function isArchive(string $path): bool
    {
        return array_key_exists($path, static::files());
    }

    /**
     * The content of an archive file (the download).
     */
    public static function read(string $path): ?string
    {
        if (static::driver() === static::DRIVER_LOCAL) {
            $full = storage_path('app/' . $path);
            return is_file($full) ? strval(file_get_contents($full)) : null;
        }
        $disk = static::disk();
        return $disk->exists($path) ? strval($disk->get($path)) : null;
    }

    public static function totalSize(): int
    {
        $size = 0;
        foreach (static::files() as $file) {
            $size += $file['size'];
        }
        return $size;
    }

    protected static function path(string $kind, string $month, string $type): string
    {
        $kind = preg_replace('/[^a-z0-9_]/', '', strtolower($kind)) ?: 'log';
        $month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : Carbon::now()->format('Y-m');
        return static::ROOT . "/{$kind}/{$kind}-{$month}.{$type}.gz";
    }

    /**
     * @param array<int, mixed> $row
     */
    protected static function csvLine(array $row): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, array_map(function ($value) {
            return is_scalar($value) || is_null($value) ? strval($value) : strval(json_encode($value, JSON_UNESCAPED_UNICODE));
        }, $row));
        rewind($handle);
        $line = strval(stream_get_contents($handle));
        fclose($handle);
        return $line;
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected static function disk()
    {
        return Storage::disk(Define::DISKNAME_BACKUP);
    }

    protected static function exists(string $path): bool
    {
        if (static::driver() === static::DRIVER_LOCAL) {
            return is_file(storage_path('app/' . $path));
        }
        return static::disk()->exists($path);
    }

    protected static function append(string $path, string $bytes): void
    {
        if (static::driver() === static::DRIVER_LOCAL) {
            $full = storage_path('app/' . $path);
            foreach ([dirname(dirname($full)), dirname($full)] as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                @chmod($dir, 0700);
            }
            if (file_put_contents($full, $bytes, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('The archive could not be written: ' . $path);
            }
            @chmod($full, 0600);
            return;
        }
        // a disk such as S3 has no append: the month is written again with the new member
        $disk = static::disk();
        $before = $disk->exists($path) ? strval($disk->get($path)) : '';
        if (!$disk->put($path, $before . $bytes)) {
            throw new \RuntimeException('The archive could not be written: ' . $path);
        }
    }

    /**
     * @return array<int, string>
     */
    protected static function listPaths(): array
    {
        if (static::driver() === static::DRIVER_LOCAL) {
            $root = storage_path('app/' . static::ROOT);
            $paths = [];
            foreach (glob($root . '/*/*.gz') ?: [] as $file) {
                $paths[] = static::ROOT . '/' . basename(dirname($file)) . '/' . basename($file);
            }
            return $paths;
        }
        try {
            return array_map('strval', static::disk()->allFiles(static::ROOT));
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function size(string $path): int
    {
        if (static::driver() === static::DRIVER_LOCAL) {
            return intval(@filesize(storage_path('app/' . $path)));
        }
        try {
            return intval(static::disk()->size($path));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected static function delete(string $path): bool
    {
        if (static::driver() === static::DRIVER_LOCAL) {
            return @unlink(storage_path('app/' . $path));
        }
        return boolval(static::disk()->delete($path));
    }
}
