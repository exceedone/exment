<?php

namespace Exceedone\Exment\Services\LogManagement;

use Carbon\Carbon;
use Exceedone\Exment\Model\BrowserLog;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\System;

/**
 * The kinds of logs of the site and how long each is kept (ISS02839). The log management, the
 * deleting (exment:log-maintenance) and the IT audit report (ISS02840) read the same list.
 *
 * A kind (register()):
 * - label: a key of the language files, translated when shown
 * - location: 'database' or a path under storage
 * - retention: function (): ?int, the days it is kept (null: kept until deleted by hand)
 * - prune: function (Carbon $before): int, delete what is older, the number deleted. Null when
 *   another job deletes it (managed_by says which)
 * - size: function (): ?int, bytes; count: function (): ?int, records or files
 * - archive, compress: bool (the archive of P3)
 *
 * types() gives each as an array: key, label, location, retention_days, archive, compress,
 * size_bytes, count, managed_by.
 */
class LogCatalog
{
    /** @var array<string, array<string, mixed>> the kinds an application or a feature added */
    protected static $registered = [];

    /** The columns of the login records in their archive. */
    public const LOGIN_LOG_COLUMNS = ['id', 'created_at', 'result', 'failure_reason', 'login_name', 'login_user_id', 'base_user_id', 'provider', 'provider_name', 'ip', 'user_agent'];

    /** The columns of the operation log in its archive. */
    public const OPERATION_LOG_COLUMNS = ['id', 'created_at', 'user_id', 'method', 'path', 'ip', 'input'];

    /** The days the login records are kept when nothing is set. */
    public const DEFAULT_LOGIN_LOG_DAYS = 365;

    /** The columns of the history of the changes of the role groups in their archive. */
    public const PERMISSION_CHANGE_COLUMNS = ['id', 'created_at', 'role_group_id', 'role_group_name', 'change', 'kind', 'role_type', 'target_type', 'target_id', 'permission', 'changed_user_id', 'source'];

    /** The days the history of the changes of the role groups is kept when nothing is set (5 years). */
    public const DEFAULT_PERMISSION_CHANGE_DAYS = 1825;

    /** The days the daily files of the application log are kept when nothing is set. */
    public const DEFAULT_APP_LOG_DAYS = 90;

    /**
     * @param array<string, mixed> $definition
     */
    public static function register(string $key, array $definition): void
    {
        static::$registered[$key] = $definition;
    }

    /** Forget the kinds that were added (tests). */
    public static function reset(): void
    {
        static::$registered = [];
    }

    /**
     * The kinds with their state.
     *
     * @return array<string, array{key: string, label: string, location: string, retention_days: int|null, archive: bool, compress: bool, size_bytes: int|null, count: int|null, managed_by: string|null}>
     */
    public static function types(): array
    {
        $types = [];
        foreach (static::definitions() as $key => $definition) {
            $types[$key] = [
                'key' => $key,
                'label' => strval($definition['label'] ?? $key),
                'location' => strval($definition['location'] ?? 'database'),
                'retention_days' => static::call($definition['retention'] ?? null),
                'archive' => boolval(static::call($definition['archive'] ?? false)),
                'compress' => boolval(static::call($definition['compress'] ?? false)),
                'size_bytes' => static::call($definition['size'] ?? null),
                'count' => static::call($definition['count'] ?? null),
                'managed_by' => $definition['managed_by'] ?? null,
            ];
        }
        return $types;
    }

    /**
     * Delete, for each kind this job deletes, what is older than it is kept.
     *
     * @return array<string, int> key => the number deleted
     */
    public static function prune(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $deleted = [];
        foreach (static::definitions() as $key => $definition) {
            $prune = $definition['prune'] ?? null;
            $days = static::call($definition['retention'] ?? null);
            if (!($prune instanceof \Closure) || !is_int($days) || $days < 1) {
                continue;
            }
            try {
                $deleted[$key] = intval($prune($now->copy()->subDays($days)));
            } catch (\Throwable $e) {
                \Log::warning('Exment log maintenance: ' . $key . ': ' . $e->getMessage());
            }
        }
        // the archives older than they are kept (part 3)
        try {
            $archives = LogArchiver::prune($now);
            if ($archives > 0) {
                $deleted['log_archive'] = $archives;
            }
        } catch (\Throwable $e) {
            \Log::warning('Exment log maintenance: log_archive: ' . $e->getMessage());
        }
        return $deleted;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function definitions(): array
    {
        return array_merge(static::core(), static::$registered);
    }

    /**
     * The kinds of Exment itself.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function core(): array
    {
        return [
            // the operation log: deleted by exment:schedule as the system setting says
            'operation_log' => [
                'archive' => function () {
                    return LogArchiver::enabled();
                },
                'compress' => function () {
                    return LogArchiver::enabled();
                },
                'label' => 'exment::exment.log_management.types.operation_log',
                'location' => 'database',
                'size' => function () {
                    return LogSize::operationLog();
                },
                'retention' => function () {
                    return boolval(System::operation_log_enable_automatic()) ? max(1, intval(System::operation_log_keep_days())) : null;
                },
                'managed_by' => 'exment:schedule',
                'count' => function () {
                    return OperationLog::query()->count();
                },
            ],
            'login_log' => [
                'archive' => function () {
                    return LogArchiver::enabled();
                },
                'compress' => function () {
                    return LogArchiver::enabled();
                },
                'label' => 'exment::exment.log_management.types.login_log',
                'location' => 'database',
                'size' => function () {
                    return LogSize::logTable('login_logs');
                },
                'retention' => function () {
                    return static::days(System::log_retention_login_log(), static::DEFAULT_LOGIN_LOG_DAYS);
                },
                'prune' => function (Carbon $before) {
                    if (!LoginLog::tableExists()) {
                        return 0;
                    }
                    $deleted = 0;
                    do {
                        $rows = LoginLog::query()->where('created_at', '<', $before)->orderBy('id')->limit(1000)->get();
                        $ids = $rows->pluck('id')->all();
                        if (!empty($ids)) {
                            // into the archive first, when it is used (part 3)
                            if (LogArchiver::enabled()) {
                                LogArchiver::rows('login_log', static::LOGIN_LOG_COLUMNS, $rows->map(function ($row) {
                                    return array_map(function ($column) use ($row) {
                                        return $column === 'created_at' ? optional($row->created_at)->format('Y-m-d H:i:s') : $row->{$column};
                                    }, static::LOGIN_LOG_COLUMNS);
                                })->all(), function ($row) {
                                    return substr(strval($row[array_search('created_at', static::LOGIN_LOG_COLUMNS, true)]), 0, 7);
                                });
                            }
                            $deleted += LoginLog::query()->whereIn('id', $ids)->delete();
                        }
                    } while (count($ids) === 1000);
                    return $deleted;
                },
                'count' => function () {
                    return LoginLog::tableExists() ? LoginLog::query()->count() : 0;
                },
            ],
            // the history of the changes of the role groups (ISS02840)
            'permission_change' => [
                'archive' => function () {
                    return LogArchiver::enabled();
                },
                'compress' => function () {
                    return LogArchiver::enabled();
                },
                'label' => 'exment::exment.log_management.types.permission_change',
                'location' => 'database',
                'size' => function () {
                    return LogSize::logTable('permission_change_logs');
                },
                'retention' => function () {
                    return static::days(System::log_retention_permission_change(), static::DEFAULT_PERMISSION_CHANGE_DAYS);
                },
                'prune' => function (Carbon $before) {
                    if (!PermissionChangeLog::tableExists()) {
                        return 0;
                    }
                    $deleted = 0;
                    do {
                        $rows = PermissionChangeLog::query()->where('created_at', '<', $before)->orderBy('id')->limit(1000)->get();
                        $ids = $rows->pluck('id')->all();
                        if (!empty($ids)) {
                            if (LogArchiver::enabled()) {
                                LogArchiver::rows('permission_change', static::PERMISSION_CHANGE_COLUMNS, $rows->map(function ($row) {
                                    return array_map(function ($column) use ($row) {
                                        return $column === 'created_at' ? optional($row->created_at)->format('Y-m-d H:i:s') : $row->{$column};
                                    }, static::PERMISSION_CHANGE_COLUMNS);
                                })->all(), function ($row) {
                                    return substr(strval($row[1]), 0, 7);
                                });
                            }
                            $deleted += PermissionChangeLog::query()->whereIn('id', $ids)->delete();
                        }
                    } while (count($ids) === 1000);
                    return $deleted;
                },
                'count' => function () {
                    return PermissionChangeLog::tableExists() ? PermissionChangeLog::query()->count() : 0;
                },
            ],
            // the reads and the writes of the data (ISS02905): the rows, deleted only once counted
            'data_access' => [
                'archive' => function () {
                    return LogArchiver::enabled();
                },
                'compress' => function () {
                    return LogArchiver::enabled();
                },
                'label' => 'exment::exment.log_management.types.data_access',
                'location' => 'database',
                'size' => function () {
                    return LogSize::logTable('data_access_logs');
                },
                'retention' => function () {
                    return static::days(System::log_retention_data_access(), DataAccessLogs::DEFAULT_DAYS);
                },
                'prune' => function (Carbon $before) {
                    if (!DataAccessLog::tableExists()) {
                        return 0;
                    }
                    // the days not counted yet stay
                    $until = DataAccessLogs::summarizedUntil();
                    if (is_null($until)) {
                        return 0;
                    }
                    if ($until->copy()->addDay()->lt($before)) {
                        $before = $until->copy()->addDay();
                    }
                    $deleted = 0;
                    do {
                        $rows = DataAccessLog::query()->where('created_at', '<', $before)->orderBy('id')->limit(1000)->get();
                        $ids = $rows->pluck('id')->all();
                        if (!empty($ids)) {
                            if (LogArchiver::enabled()) {
                                LogArchiver::rows('data_access', DataAccessLogs::COLUMNS, $rows->map(function ($row) {
                                    return array_map(function ($column) use ($row) {
                                        return $column === 'created_at' ? optional($row->created_at)->format('Y-m-d H:i:s') : $row->{$column};
                                    }, DataAccessLogs::COLUMNS);
                                })->all(), function ($row) {
                                    return substr(strval($row[1]), 0, 7);
                                });
                            }
                            $deleted += DataAccessLog::query()->whereIn('id', $ids)->delete();
                        }
                    } while (count($ids) === 1000);
                    return $deleted;
                },
                'count' => function () {
                    return DataAccessLog::tableExists() ? DataAccessLog::query()->count() : 0;
                },
            ],
            // their counts of each day, which the IT audit report reads (kept longer)
            'data_access_daily' => [
                'label' => 'exment::exment.log_management.types.data_access_daily',
                'location' => 'database',
                'size' => function () {
                    return LogSize::logTable('data_access_daily');
                },
                'retention' => function () {
                    return static::days(System::log_retention_data_access_daily(), DataAccessLogs::DEFAULT_DAILY_DAYS);
                },
                'prune' => function (Carbon $before) {
                    return DataAccessLog::tableExists() ? DataAccessDaily::query()->where('date', '<', $before->format('Y-m-d'))->delete() : 0;
                },
                'count' => function () {
                    return DataAccessLog::tableExists() ? DataAccessDaily::query()->count() : 0;
                },
            ],
            // the files of the application log, one a day (laravel-YYYY-MM-DD.log of Laravel's
            // daily channel). A single laravel.log is not deleted: it is the one being written
            'app_log' => [
                'archive' => function () {
                    return LogArchiver::enabled();
                },
                'compress' => function () {
                    return LogArchiver::enabled();
                },
                'label' => 'exment::exment.log_management.types.app_log',
                'location' => 'storage/logs',
                'retention' => function () {
                    return static::days(System::log_retention_app_log(), static::DEFAULT_APP_LOG_DAYS);
                },
                'prune' => function (Carbon $before) {
                    $deleted = 0;
                    foreach (static::appLogFiles() as $file => $date) {
                        if ($date >= $before->format('Y-m-d')) {
                            continue;
                        }
                        // into the archive first, when it is used (part 3): not deleted when it could not be
                        if (LogArchiver::enabled() && !LogArchiver::file('app_log', substr($date, 0, 7), $file)) {
                            continue;
                        }
                        if (@unlink($file)) {
                            $deleted++;
                        }
                    }
                    return $deleted;
                },
                'size' => function () {
                    $size = 0;
                    foreach (glob(storage_path('logs') . '/*.log') ?: [] as $file) {
                        $size += intval(@filesize($file));
                    }
                    return $size;
                },
                'count' => function () {
                    return count(glob(storage_path('logs') . '/*.log') ?: []);
                },
            ],
            // the log of the browsers (ISS02904)
            'browser_log' => [
                'archive' => function () {
                    return LogArchiver::enabled();
                },
                'compress' => function () {
                    return LogArchiver::enabled();
                },
                'label' => 'exment::exment.log_management.types.browser_log',
                'location' => 'database',
                'size' => function () {
                    return LogSize::logTable('browser_logs');
                },
                'retention' => function () {
                    return static::days(System::log_retention_browser_log(), BrowserLogs::DEFAULT_DAYS);
                },
                'prune' => function (Carbon $before) {
                    if (!BrowserLog::tableExists()) {
                        return 0;
                    }
                    $deleted = 0;
                    do {
                        $rows = BrowserLog::query()->where('created_at', '<', $before)->orderBy('id')->limit(1000)->get();
                        $ids = $rows->pluck('id')->all();
                        if (!empty($ids)) {
                            if (LogArchiver::enabled()) {
                                LogArchiver::rows('browser_log', BrowserLogs::COLUMNS, $rows->map(function ($row) {
                                    return array_map(function ($column) use ($row) {
                                        return $column === 'created_at' ? optional($row->created_at)->format('Y-m-d H:i:s') : $row->{$column};
                                    }, BrowserLogs::COLUMNS);
                                })->all(), function ($row) {
                                    return substr(strval($row[array_search('created_at', BrowserLogs::COLUMNS, true)]), 0, 7);
                                });
                            }
                            $deleted += BrowserLog::query()->whereIn('id', $ids)->delete();
                        }
                    } while (count($ids) === 1000);
                    return $deleted;
                },
                'count' => function () {
                    return BrowserLog::tableExists() ? BrowserLog::query()->count() : 0;
                },
            ],
        ];
    }

    /**
     * The daily files of the application log: path => date (Y-m-d).
     *
     * @return array<string, string>
     */
    public static function appLogFiles(): array
    {
        $files = [];
        foreach (glob(storage_path('logs') . '/*-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log') ?: [] as $file) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) === 1) {
                $files[$file] = $m[1];
            }
        }
        return $files;
    }

    /**
     * The days of a setting: null (kept) for 0 or less, the default when nothing is set.
     *
     * @param mixed $value
     */
    protected static function days($value, int $default): ?int
    {
        if (is_null($value) || $value === '') {
            return $default;
        }
        $days = intval($value);
        return $days > 0 ? $days : null;
    }

    /**
     * @param mixed $callable
     * @return mixed
     */
    protected static function call($callable)
    {
        if (!($callable instanceof \Closure)) {
            return is_scalar($callable) || is_null($callable) ? $callable : null;
        }
        try {
            return $callable();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
