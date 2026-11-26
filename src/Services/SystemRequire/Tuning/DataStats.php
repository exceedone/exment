<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Storage;

/**
 * The counts of the data for the tuning (ISS02927): the rows, the deleted rows and the revisions
 * of each table, the sizes the database tells, and the attachments. Counted every night
 * (exment:tuning-data) and kept (system "tuning_data_stats"): the tuning page reads what was
 * counted, it never counts. Only reads the data.
 */
class DataStats
{
    /** Above this number of files, the size is counted for the first ones only. */
    public const FILE_SIZE_LIMIT = 5000;

    /**
     * What was counted last (null: not yet).
     *
     * @return array<string, mixed>|null
     */
    public static function load(): ?array
    {
        try {
            // kept as JSON, read back as objects: as arrays again
            $stats = json_decode(strval(json_encode(System::tuning_data_stats())), true);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($stats) && isset($stats['time']) ? $stats : null;
    }

    /**
     * Count and keep.
     *
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $previous = static::load();
        $stats = [
            'time' => time(),
            'tables' => static::tables($previous),
            'files' => static::files(),
            'database' => ['bytes' => null],
        ];
        $bytes = null;
        foreach ($stats['tables'] as $table) {
            if (is_int($table['data_bytes'])) {
                $bytes = ($bytes ?? 0) + $table['data_bytes'] + intval($table['index_bytes']);
            }
        }
        $stats['database']['bytes'] = $bytes;
        System::tuning_data_stats($stats);
        // only this setting is read again (not the whole cache)
        System::clearCache(sprintf(\Exceedone\Exment\Model\Define::SYSTEM_KEY_SESSION_SYSTEM_CONFIG, 'tuning_data_stats'));
        return $stats;
    }

    /**
     * Whether the database tells the sizes and the settings read here (MySQL, MariaDB).
     */
    public static function isMySql(): bool
    {
        try {
            return in_array(\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $previous
     * @return array<string, array{rows: int, deleted: int, revisions: int, data_bytes: int|null, index_bytes: int|null, previous_rows: int|null, previous_time: int|null}>
     */
    protected static function tables(?array $previous): array
    {
        $sizes = static::sizes();
        // the revisions of all the tables in one query
        $revisions = [];
        try {
            foreach (\DB::table('revisions')->selectRaw('revisionable_type, count(*) as cnt')->groupBy('revisionable_type')->get() as $row) {
                $revisions[strval($row->revisionable_type)] = intval($row->cnt);
            }
        } catch (\Throwable $e) {
        }

        $result = [];
        foreach (CustomTable::allRecords() as $table) {
            $dbTable = getDBTableName($table);
            if (!hasTable($dbTable)) {
                continue;
            }
            try {
                $all = \DB::table($dbTable)->count();
                $deleted = \DB::table($dbTable)->whereNotNull('deleted_at')->count();
            } catch (\Throwable $e) {
                continue;
            }
            $name = strval($table->table_name);
            $before = $previous['tables'][$name] ?? null;
            $result[$name] = [
                'rows' => $all - $deleted,
                'deleted' => $deleted,
                'revisions' => $revisions[$name] ?? 0,
                'data_bytes' => $sizes[$dbTable]['data'] ?? null,
                'index_bytes' => $sizes[$dbTable]['index'] ?? null,
                'previous_rows' => is_array($before) ? intval($before['rows'] ?? 0) : null,
                'previous_time' => is_array($before) ? intval($previous['time'] ?? 0) : null,
            ];
        }
        return $result;
    }

    /**
     * The sizes of the tables (MySQL, MariaDB; the others: none).
     *
     * @return array<string, array{data: int, index: int}>
     */
    protected static function sizes(): array
    {
        if (!static::isMySql()) {
            return [];
        }
        $sizes = [];
        try {
            $prefix = strval(\DB::connection()->getTablePrefix());
            foreach (\DB::select('select table_name as name, data_length as data_bytes, index_length as index_bytes from information_schema.tables where table_schema = database()') as $row) {
                $name = strval($row->name);
                if ($prefix !== '' && str_starts_with($name, $prefix)) {
                    $name = substr($name, strlen($prefix));
                }
                $sizes[$name] = ['data' => intval($row->data_bytes), 'index' => intval($row->index_bytes)];
            }
        } catch (\Throwable $e) {
        }
        return $sizes;
    }

    /**
     * The attachments: how many, their size (of the first FILE_SIZE_LIMIT), and the ones of data
     * that is no more (deleted for good; the deleted data in the trash keeps its files).
     *
     * @return array{count: int, bytes: int, bytes_partial: bool, orphans: int}
     */
    protected static function files(): array
    {
        $result = ['count' => 0, 'bytes' => 0, 'bytes_partial' => false, 'orphans' => 0];
        try {
            $result['count'] = ExmentFile::query()->count();
            $disk = Storage::disk(config('admin.upload.disk'));
            $sized = 0;
            foreach (ExmentFile::query()->orderBy('created_at', 'desc')->limit(static::FILE_SIZE_LIMIT)->cursor() as $file) {
                $sized++;
                try {
                    $result['bytes'] += intval($disk->size($file->path));
                } catch (\Throwable $e) {
                }
            }
            $result['bytes_partial'] = $result['count'] > $sized;

            foreach (CustomTable::allRecords() as $table) {
                $dbTable = getDBTableName($table);
                if (!hasTable($dbTable)) {
                    continue;
                }
                $result['orphans'] += ExmentFile::query()
                    ->where('parent_type', $table->table_name)
                    ->whereNotNull('parent_id')
                    ->whereNotExists(function ($query) use ($dbTable) {
                        $query->selectRaw('1')->from($dbTable)->whereColumn("{$dbTable}.id", 'files.parent_id');
                    })->count();
            }
        } catch (\Throwable $e) {
            // before the install, there are no tables
        }
        return $result;
    }
}
