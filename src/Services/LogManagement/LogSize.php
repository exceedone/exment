<?php

namespace Exceedone\Exment\Services\LogManagement;

/**
 * How large the logs are (ISS02905): the log tables (with their indexes), the operation log, the
 * files of the application log and the archives; and a warning when they pass
 * config exment.log_size_warn_mb (EXMENT_LOG_SIZE_WARN_MB, 1024 MB; 0: no warning), on the page of
 * the log management and in the security check.
 */
class LogSize
{
    public const DEFAULT_WARN_MB = 1024;

    /** @var array<string, int|null> the sizes found in this request */
    protected static $tables = [];

    /** @var int|null the total (tests) */
    public static $total = null;

    public static function reset(): void
    {
        static::$tables = [];
        static::$total = null;
    }

    /**
     * The bytes of a table (data and indexes), null when the database does not say (or the table is
     * not there).
     */
    public static function table(string $table, ?string $connection = null): ?int
    {
        $key = strval($connection) . '|' . $table;
        if (array_key_exists($key, static::$tables)) {
            return static::$tables[$key];
        }
        $size = null;
        try {
            $db = \DB::connection($connection);
            $name = $db->getTablePrefix() . $table;
            switch ($db->getDriverName()) {
                case 'mysql':
                case 'mariadb':
                    $row = $db->selectOne('select data_length + index_length as bytes from information_schema.tables where table_schema = database() and table_name = ?', [$name]);
                    $size = isset($row) ? intval($row->bytes) : null;
                    break;
                case 'pgsql':
                    $row = $db->selectOne('select pg_total_relation_size(to_regclass(?)) as bytes', [$name]);
                    $size = isset($row) && !is_null($row->bytes) ? intval($row->bytes) : null;
                    break;
                case 'sqlsrv':
                    $row = $db->selectOne('select sum(a.total_pages) * 8192 as bytes from sys.tables t join sys.partitions p on t.object_id = p.object_id join sys.allocation_units a on p.partition_id = a.container_id where t.name = ?', [$name]);
                    $size = isset($row) && !is_null($row->bytes) ? intval($row->bytes) : null;
                    break;
            }
        } catch (\Throwable $e) {
            $size = null;
        }
        return static::$tables[$key] = $size;
    }

    /** The bytes of the log tables on their connection. */
    public static function logTable(string $table): ?int
    {
        return static::table($table, LogConnection::name());
    }

    /** The bytes of the operation log (the connection of the administrators). */
    public static function operationLog(): ?int
    {
        return static::table(strval(config('admin.database.operation_log_table', 'admin_operation_log')), config('admin.database.connection') ?: null);
    }

    /** The bytes of the files of a folder (not in its folders). */
    public static function files(string $dir, string $pattern = '*'): int
    {
        $size = 0;
        foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
            if (is_file($file)) {
                $size += intval(@filesize($file));
            }
        }
        return $size;
    }

    /** All the logs: the tables, the operation log, the files of the application log and the archives. */
    public static function total(): int
    {
        if (isset(static::$total)) {
            return static::$total;
        }
        $total = intval(static::operationLog());
        foreach (LogConnection::TABLES as $table) {
            $total += intval(static::logTable($table));
        }
        $total += static::files(storage_path('logs'), '*.log');
        if (is_dir(storage_path('app/log_archive'))) {
            foreach (glob(storage_path('app/log_archive') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $total += static::files($dir);
            }
            $total += static::files(storage_path('app/log_archive'));
        }
        return $total;
    }

    /** The limit in bytes, null: no warning. */
    public static function limit(): ?int
    {
        $mb = config('exment.log_size_warn_mb', static::DEFAULT_WARN_MB);
        if (!is_numeric($mb) || floatval($mb) <= 0) {
            return null;
        }
        return intval(floatval($mb) * 1024 * 1024);
    }

    public static function over(): bool
    {
        $limit = static::limit();
        return isset($limit) && static::total() > $limit;
    }

    /**
     * The warning, null when the logs are under the limit.
     *
     * @param string $key the text (exment::exment.<key>, with :size and :limit)
     */
    public static function warning(string $key = 'log_management.size_warning'): ?string
    {
        if (!static::over()) {
            return null;
        }
        return strval(exmtrans($key, [
            'size' => \Exceedone\Exment\Controllers\LogManagementController::bytes(static::total()),
            'limit' => \Exceedone\Exment\Controllers\LogManagementController::bytes(static::limit()),
        ]));
    }
}
