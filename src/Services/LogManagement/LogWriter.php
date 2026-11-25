<?php

namespace Exceedone\Exment\Services\LogManagement;

use Exceedone\Exment\Jobs\WriteLogsJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the records of the logs without making the response wait (ISS02903): the operation log,
 * the logins, the last use of an API key. A record is made at once (its time, its IP, what is
 * hidden); only the write moves. As config "exment.log_write_mode" (EXMENT_LOG_WRITE_MODE) says:
 *
 * - after_response (the default): after the response is sent (the middleware WriteLogsAfterResponse,
 *   its terminate; with PHP-FPM the browser has the response before), all the rows of a table in
 *   one INSERT
 * - queue: the same rows in a job (WriteLogsJob), when there is a queue; without one, after_response
 * - sync: at once, as before
 *
 * Outside a web request (a command, a queue worker, a test without a request) a record is written
 * at once. A request that ends with exit writes its records at the shutdown. A record that cannot
 * be written is kept in storage/logs/exment-log-fallback.log (600), never lost: a row of an INSERT
 * of many is written again alone, the rows that still fail go to the file.
 *
 * The counters of the security (the failed logins that lock) are not records: they are counted at
 * once, where they are (ThrottlesLogins).
 */
class LogWriter
{
    public const MODE_SYNC = 'sync';
    public const MODE_AFTER_RESPONSE = 'after_response';
    public const MODE_QUEUE = 'queue';
    public const MODES = [self::MODE_SYNC, self::MODE_AFTER_RESPONSE, self::MODE_QUEUE];

    /** The rows kept at most before they are written (a long request: an import). */
    public const MAX_PENDING = 500;

    /** The rows in one INSERT. */
    public const CHUNK = 200;

    public const FALLBACK_FILE = 'exment-log-fallback.log';

    /** @var bool whether a web request is running (the records wait for its end) */
    protected static $deferring = false;

    /** @var bool whether the shutdown writes what is left */
    protected static $shutdownRegistered = false;

    /**
     * The rows waiting: "connection|table" => [connection, table, rows].
     *
     * @var array<string, array{0: string|null, 1: string, 2: array<int, array<string, mixed>>}>
     */
    protected static $rows = [];

    /** @var array<int, array{0: string, 1: \Closure}> the other writes waiting (what, the write) */
    protected static $writes = [];

    /** @var int */
    protected static $count = 0;

    /**
     * The mode set (config), a mode not known is the default.
     */
    public static function configuredMode(): string
    {
        $mode = strval(config('exment.log_write_mode', self::MODE_AFTER_RESPONSE));
        return in_array($mode, static::MODES, true) ? $mode : self::MODE_AFTER_RESPONSE;
    }

    /**
     * How a record is written now.
     */
    public static function mode(): string
    {
        $mode = static::configuredMode();
        if ($mode === self::MODE_SYNC || !static::$deferring) {
            return self::MODE_SYNC;
        }
        if ($mode === self::MODE_QUEUE && !static::hasQueue()) {
            return self::MODE_AFTER_RESPONSE;
        }
        return $mode;
    }

    public static function hasQueue(): bool
    {
        $connection = strval(config('queue.default', 'sync'));
        return $connection !== '' && $connection !== 'sync' && $connection !== 'null';
    }

    /**
     * A web request starts: its records wait for its end.
     */
    public static function begin(): void
    {
        static::$deferring = true;
        if (!static::$shutdownRegistered) {
            static::$shutdownRegistered = true;
            register_shutdown_function(function () {
                static::flush();
            });
        }
    }

    /**
     * The web request ended (the response was sent): write its records.
     */
    public static function end(): void
    {
        try {
            static::flush();
        } finally {
            static::$deferring = false;
        }
    }

    /**
     * Keep a row of a log (a model of a table of a log: the table and the connection of the
     * model). The timestamps of the model are set here, when the model has them and the row not.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $row
     */
    public static function insert(string $model, array $row): void
    {
        /** @var Model $instance */
        $instance = new $model();
        if ($instance->usesTimestamps()) {
            $now = $instance->freshTimestampString();
            foreach ([$instance->getCreatedAtColumn(), $instance->getUpdatedAtColumn()] as $column) {
                if (isset($column) && !array_key_exists($column, $row)) {
                    $row[$column] = $now;
                }
            }
        }
        $row = array_map(function ($value) {
            return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
        }, $row);
        $connection = $instance->getConnectionName();
        $table = $instance->getTable();

        if (static::mode() === self::MODE_SYNC) {
            static::writeRows($connection, $table, [$row]);
            return;
        }
        $key = strval($connection) . '|' . $table;
        if (!isset(static::$rows[$key])) {
            static::$rows[$key] = [$connection, $table, []];
        }
        static::$rows[$key][2][] = $row;
        static::pending();
    }

    /**
     * Another write of a record (an UPDATE: the last use of an API key). It runs after the
     * response (also in the mode queue: a closure is not sent to the queue).
     *
     * @param string $what what is written, for the log when it fails
     */
    public static function defer(string $what, \Closure $write): void
    {
        if (static::configuredMode() === self::MODE_SYNC || !static::$deferring) {
            static::run($what, $write);
            return;
        }
        static::$writes[] = [$what, $write];
        static::pending();
    }

    /**
     * Write all the records waiting.
     */
    public static function flush(): void
    {
        $rows = static::$rows;
        $writes = static::$writes;
        static::$rows = [];
        static::$writes = [];
        static::$count = 0;

        if (count($rows) > 0) {
            $batches = array_values($rows);
            if (static::configuredMode() === self::MODE_QUEUE && static::hasQueue()) {
                try {
                    WriteLogsJob::dispatch($batches);
                    $batches = [];
                } catch (\Throwable $e) {
                    \Log::warning('Exment: the records of the logs were not sent to the queue, written now: ' . get_class($e));
                }
            }
            foreach ($batches as [$connection, $table, $tableRows]) {
                static::writeRows($connection, $table, $tableRows);
            }
        }
        foreach ($writes as [$what, $write]) {
            static::run($what, $write);
        }
    }

    /**
     * Write rows to a table: all in INSERTs of CHUNK rows; the rows of an INSERT that fails one by
     * one; a row that still fails to the file (never lost).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function writeRows(?string $connection, string $table, array $rows): void
    {
        // an INSERT of many rows has the columns of its first row: the rows with the same columns
        // together, in their order (a row of the logins leaves out the columns it has no value for)
        $chunks = [];
        $columns = null;
        foreach ($rows as $row) {
            ksort($row);
            $keys = implode(',', array_keys($row));
            $last = count($chunks) - 1;
            if ($keys !== $columns || count($chunks[$last]) >= static::CHUNK) {
                $chunks[] = [];
                $last++;
                $columns = $keys;
            }
            $chunks[$last][] = $row;
        }
        foreach ($chunks as $chunk) {
            try {
                \DB::connection($connection)->table($table)->insert($chunk);
                continue;
            } catch (\Throwable $e) {
                if (count($chunk) === 1) {
                    static::fallback($table, $chunk[0], $e);
                    continue;
                }
            }
            foreach ($chunk as $row) {
                try {
                    \DB::connection($connection)->table($table)->insert($row);
                } catch (\Throwable $e) {
                    static::fallback($table, $row, $e);
                }
            }
        }
    }

    /**
     * The rows waiting (the tests).
     */
    public static function pendingCount(): int
    {
        return static::$count;
    }

    /**
     * Forget everything waiting, and that a request runs (the tests).
     */
    public static function reset(): void
    {
        static::$rows = [];
        static::$writes = [];
        static::$count = 0;
        static::$deferring = false;
    }

    public static function fallbackPath(): string
    {
        return storage_path('logs/' . static::FALLBACK_FILE);
    }

    protected static function pending(): void
    {
        static::$count++;
        if (static::$count >= static::MAX_PENDING) {
            static::flush();
        }
    }

    protected static function run(string $what, \Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            \Log::warning('Exment: ' . $what . ' was not recorded: ' . get_class($e));
        }
    }

    /**
     * Keep a row that could not be written: a line of JSON (the table, the row as it was made,
     * already hidden as the log hides it, and the class of the error; never its message, it may
     * hold the values).
     *
     * @param array<string, mixed> $row
     */
    protected static function fallback(string $table, array $row, \Throwable $e): void
    {
        $line = json_encode([
            'at' => date('Y-m-d H:i:s'),
            'table' => $table,
            'error' => get_class($e),
            'row' => $row,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $path = static::fallbackPath();
        try {
            $new = !is_file($path);
            if (@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
                if ($new) {
                    @chmod($path, 0600);
                }
                return;
            }
        } catch (\Throwable $e2) {
            // below
        }
        \Log::error('Exment: a record of ' . $table . ' was not written: ' . get_class($e));
    }
}
