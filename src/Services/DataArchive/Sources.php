<?php

namespace Exceedone\Exment\Services\DataArchive;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\LogManagement\LogConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * What the data archive takes old rows from (ISS02970, part 1): the tables of Exment that grow,
 * each with its date and the rows that are never taken.
 *
 * A source: key => [label, table (a name, or a closure for the table of a system custom table),
 * log (on the connection of the logs), date (the column of the age), scope (a closure that keeps
 * rows out), custom (a table of custom values: only the columns of custom_values are archived and
 * restored, never the generated index columns)].
 */
class Sources
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $api = ($prefix === '' ? '' : $prefix . '/') . 'api/%';
        return [
            'revisions' => [
                'table' => 'revisions', 'log' => false, 'date' => 'created_at',
                // the newest change of a record that is still there stays (its history starts there)
                'scope' => function (Builder $query) {
                    $query->whereNotIn('id', function ($sub) {
                        $sub->from('revisions')->selectRaw('max(id)')->groupBy('revisionable_type', 'revisionable_id');
                    });
                },
            ],
            // the bells (a table of its own)
            'notify_navbars' => ['table' => 'notify_navbars', 'log' => false, 'date' => 'created_at'],
            'mail_send_log' => ['table' => SystemTableName::MAIL_SEND_LOG, 'custom' => true, 'log' => false, 'date' => 'created_at'],
            'operation_logs' => ['table' => strval(config('admin.database.operation_log_table', 'admin_operation_log')), 'log' => false, 'date' => 'created_at'],
            // the calls of the API in the operation log (a part of operation_logs)
            'api_operation_logs' => [
                'table' => strval(config('admin.database.operation_log_table', 'admin_operation_log')), 'log' => false, 'date' => 'created_at',
                'scope' => function (Builder $query) use ($api) {
                    $query->where('path', 'like', $api);
                },
            ],
            'data_access_logs' => ['table' => 'data_access_logs', 'log' => true, 'date' => 'created_at'],
            'mcp_logs' => ['table' => 'mcp_logs', 'log' => true, 'date' => 'created_at'],
            'login_logs' => ['table' => 'login_logs', 'log' => true, 'date' => 'created_at'],
            'browser_logs' => ['table' => 'browser_logs', 'log' => true, 'date' => 'created_at'],
            'deleted_archives' => ['table' => 'deleted_archives', 'log' => false, 'date' => 'archived_at'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }

    public static function connection(string $key): Connection
    {
        $source = static::get($key);
        return boolval($source['log'] ?? false) ? LogConnection::db() : \DB::connection();
    }

    /**
     * The table in the database (a system custom table: its table of values), null when it is not there.
     */
    public static function table(string $key): ?string
    {
        $source = static::get($key);
        if (!isset($source)) {
            return null;
        }
        if (boolval($source['custom'] ?? false)) {
            $table = CustomTable::getEloquent(strval($source['table']));
            if (!isset($table)) {
                return null;
            }
            $name = getDBTableName($table);
        } else {
            $name = strval($source['table']);
        }
        try {
            return static::connection($key)->getSchemaBuilder()->hasTable($name) ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The columns archived and restored: those of the table (of custom_values for a table of values).
     *
     * @return array<int, string>
     */
    public static function columns(string $key): array
    {
        $source = static::get($key);
        $table = static::table($key);
        if (!isset($source, $table)) {
            return [];
        }
        $schema = static::connection($key)->getSchemaBuilder();
        $columns = $schema->getColumnListing(boolval($source['custom'] ?? false) ? 'custom_values' : $table);
        return array_map('strval', $columns);
    }

    /**
     * The rows older than $before (without those the source keeps).
     */
    public static function query(string $key, \Carbon\Carbon $before): ?Builder
    {
        $source = static::get($key);
        $table = static::table($key);
        if (!isset($source, $table)) {
            return null;
        }
        $query = static::connection($key)->table($table)->where(strval($source['date']), '<', $before);
        if (isset($source['scope']) && $source['scope'] instanceof \Closure) {
            $source['scope']($query);
        }
        return $query;
    }
}
