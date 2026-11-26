<?php

namespace Exceedone\Exment\Services\LogManagement;

/**
 * The database of the log tables (ISS02905): config exment.log_db_connection
 * (EXMENT_LOG_DB_CONNECTION), a connection of config/database.php, so that the logs do not fill the
 * database of the data. Empty: the default connection, as before.
 *
 * The tables: login_logs, permission_change_logs, browser_logs, data_access_logs,
 * data_access_daily. The operation log follows config admin.database.connection (the tables of
 * the administrators), not this. A site that already has the tables makes them on the new
 * connection and copies the rows with exment:log-db.
 */
class LogConnection
{
    public const TABLES = ['login_logs', 'permission_change_logs', 'browser_logs', 'data_access_logs', 'data_access_daily', 'mcp_logs'];

    /** The connection of the log tables, null: the default. */
    public static function name(): ?string
    {
        $name = config('exment.log_db_connection');
        if (!is_string($name) || $name === '') {
            return null;
        }
        // a name that is not a connection: the default, rather than no log at all
        return is_array(config('database.connections.' . $name)) ? $name : null;
    }

    public static function schema(): \Illuminate\Database\Schema\Builder
    {
        return \Schema::connection(static::name());
    }

    public static function db(): \Illuminate\Database\Connection
    {
        return \DB::connection(static::name());
    }
}
