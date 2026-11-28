<?php

namespace Exceedone\Exment\Services\Insights;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\BrowserLog;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\McpLog;
use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\ScheduledJobRun;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\LogManagement\DataAccessLogs;
use Exceedone\Exment\Services\SystemRequire\Tuning\DataStats;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;

/**
 * The insights from the logs (ISS02964): the numbers of each day, counted every night from the
 * logs (exment:insights) into the small table insight_daily, which the page reads. A few are
 * counted when they happen (hit()): what no log keeps (a webhook not sent, a refused API key).
 *
 * The metrics (and their keys):
 * - active_day, active_week, active_month: the users who read or wrote data or signed in, in the
 *   day, the 7 days and the 30 days ending that day
 * - view_use (view id): the lists shown with a view
 * - table_rows (table name): the rows of each table that night; storage (attachments, file_server): bytes
 * - login (success, failure, lockout): the sign-ins
 * - permission_change, admin_change (the page: role_group, system...): the changes of the settings
 * - api_key_use: the calls with the users' API keys; api_key_refused (reason)
 * - mcp (ok, error...), mcp_tool (tool): the calls of the AI
 * - mail (sent, failed), webhook_failed (host)
 * - browser (error, request, page), browser_error (path), slow_page (path)
 * - job (success, failed, skipped), job_failed (the job)
 * - plugin_use (the plugin): the pages of the plugins opened
 */
class Insights
{
    public const FEATURE = 'insights';

    /** A page shown in more time than this is slow (milliseconds). */
    public const SLOW_PAGE_MS = 3000;

    /** The paths kept a day for the errors and the slow pages (the most frequent first). */
    public const TOP_PATHS = 20;

    /** The days counted again by the nightly run when no day was counted yet. */
    public const FIRST_DAYS = 30;

    /** The pages whose changes count as changes of the settings (the first part of the path). */
    public const ADMIN_PAGES = [
        'system', 'role_group', 'loginuser', 'login_setting', 'api_setting', 'api_keys', 'plugin', 'feature',
        'table', 'column', 'form', 'view', 'relation', 'copy', 'operation', 'notify', 'workflow', 'backup',
        'maintenance', 'log_management', 'auth/menu',
    ];

    /** The metrics counted for today when the page is opened (cheap: indexed by the time). */
    public const LIVE = ['active_day', 'login', 'mcp', 'mcp_tool', 'browser', 'browser_error', 'slow_page', 'job', 'job_failed', 'permission_change', 'view_use'];

    /** @var Carbon|null "now" in the tests */
    public static $now;

    public static function now(): Carbon
    {
        return isset(static::$now) ? static::$now->copy() : Carbon::now();
    }

    public static function enabled(): bool
    {
        return Features::enabled(static::FEATURE) && InsightDaily::tableExists();
    }

    // ============================================================ the nightly count

    /**
     * Count the days not counted yet, up to yesterday (at most FIRST_DAYS back the first time),
     * or the days asked.
     *
     * @return array<int, string> the days counted
     */
    public static function collectUntilYesterday(?Carbon $from = null, ?Carbon $to = null): array
    {
        $yesterday = static::now()->startOfDay()->subDay();
        $to = isset($to) ? $to->copy()->startOfDay() : $yesterday;
        if (!isset($from)) {
            $last = InsightDaily::query()->where('metric', 'active_day')->max('date');
            $from = isset($last) ? Carbon::parse($last)->startOfDay()->addDay() : $to->copy()->subDays(static::FIRST_DAYS - 1);
        }
        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            static::collect($day);
            $days[] = $day->format('Y-m-d');
        }
        return $days;
    }

    /**
     * Count a day and keep it (the rows of the day are replaced; the counters of hit() stay).
     */
    public static function collect(Carbon $day): void
    {
        $day = $day->copy()->startOfDay();
        $rows = static::day($day, false);
        \DB::transaction(function () use ($day, $rows) {
            $keep = static::HIT_METRICS;
            if (!$day->eq(static::now()->startOfDay()->subDay())) {
                // a day counted again later: its rows and bytes stay as they were that night
                $keep = array_merge($keep, ['table_rows', 'storage']);
            }
            InsightDaily::query()->where('date', $day->format('Y-m-d'))->whereNotIn('metric', $keep)->delete();
            $insert = [];
            foreach ($rows as $metric => $values) {
                foreach ($values as $key => $value) {
                    $insert[] = ['date' => $day->format('Y-m-d'), 'metric' => $metric, 'key' => static::key($key), 'value' => intval($value)];
                }
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                InsightDaily::query()->insert($chunk);
            }
        });
        static::keepApiKeyUse($day);
    }

    /**
     * The numbers of a day, not kept: metric => key => value. $live: only the cheap ones (today).
     *
     * @return array<string, array<string, int>>
     */
    public static function day(Carbon $day, bool $live): array
    {
        $from = $day->copy()->startOfDay();
        $to = $from->copy()->addDay();
        $rows = [];
        $add = function (string $metric, callable $count) use (&$rows, $live) {
            if ($live && !in_array($metric, static::LIVE, true)) {
                return;
            }
            try {
                $values = $count();
            } catch (\Throwable $e) {
                \Log::warning('[Exment] insights: ' . $metric . ': ' . $e->getMessage());
                return;
            }
            $values = array_filter(array_map('intval', $values), function ($value) {
                return $value !== 0;
            });
            if (count($values) > 0) {
                $rows[$metric] = $values;
            }
        };

        $add('active_day', function () use ($from, $to) {
            return ['' => count(static::activeUsers($from, $to))];
        });
        $add('active_week', function () use ($from, $to) {
            return ['' => count(static::activeUsers($from->copy()->subDays(6), $to))];
        });
        $add('active_month', function () use ($from, $to) {
            return ['' => count(static::activeUsers($from->copy()->subDays(29), $to))];
        });
        $add('view_use', function () use ($from, $to) {
            if (!DataAccessLog::tableExists()) {
                return [];
            }
            return DataAccessLog::query()->whereNotNull('custom_view_id')->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->selectRaw('custom_view_id, count(*) as total')->groupBy('custom_view_id')->pluck('total', 'custom_view_id')->all();
        });
        // the rows and the bytes are of now: kept only with yesterday (a day counted again later keeps what it had)
        $lastNight = $from->eq(static::now()->startOfDay()->subDay());
        if ($lastNight || $live) {
            $add('table_rows', function () {
                return static::tableRows();
            });
            $add('storage', function () {
                return static::storage();
            });
        }
        $add('login', function () use ($from, $to) {
            if (!LoginLog::tableExists()) {
                return [];
            }
            return LoginLog::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->whereIn('result', [LoginLog::RESULT_SUCCESS, LoginLog::RESULT_FAILURE, LoginLog::RESULT_LOCKOUT])
                ->selectRaw('result, count(*) as total')->groupBy('result')->pluck('total', 'result')->all();
        });
        $add('permission_change', function () use ($from, $to) {
            if (!PermissionChangeLog::tableExists()) {
                return [];
            }
            return ['' => PermissionChangeLog::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count()];
        });
        $add('admin_change', function () use ($from, $to) {
            return static::adminChanges($from, $to);
        });
        $add('plugin_use', function () use ($from, $to) {
            return static::pluginUse($from, $to);
        });
        $add('mcp', function () use ($from, $to) {
            if (!McpLog::tableExists()) {
                return [];
            }
            return McpLog::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        });
        $add('mcp_tool', function () use ($from, $to) {
            if (!McpLog::tableExists()) {
                return [];
            }
            return McpLog::query()->whereNotNull('tool')->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->selectRaw('tool, count(*) as total')->groupBy('tool')->pluck('total', 'tool')->all();
        });
        $add('mail', function () use ($from, $to) {
            return static::mails($from, $to);
        });
        $add('browser', function () use ($from, $to) {
            if (!BrowserLog::tableExists()) {
                return [];
            }
            return BrowserLog::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->selectRaw('kind, sum(count) as total')->groupBy('kind')->pluck('total', 'kind')->all();
        });
        $add('browser_error', function () use ($from, $to) {
            if (!BrowserLog::tableExists()) {
                return [];
            }
            return BrowserLog::query()->whereIn('kind', [BrowserLog::KIND_ERROR, BrowserLog::KIND_REQUEST])
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->selectRaw('path, sum(count) as total')->groupBy('path')->orderByDesc('total')->limit(static::TOP_PATHS)->pluck('total', 'path')->all();
        });
        $add('slow_page', function () use ($from, $to) {
            if (!BrowserLog::tableExists()) {
                return [];
            }
            return BrowserLog::query()->where('kind', BrowserLog::KIND_PAGE)->where('duration_ms', '>=', static::SLOW_PAGE_MS)
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->selectRaw('path, sum(count) as total')->groupBy('path')->orderByDesc('total')->limit(static::TOP_PATHS)->pluck('total', 'path')->all();
        });
        $add('job', function () use ($from, $to) {
            if (!\Schema::hasTable(ScheduledJobRun::TABLE)) {
                return [];
            }
            return ScheduledJobRun::query()->where('started_at', '>=', $from)->where('started_at', '<', $to)
                ->whereIn('result', [ScheduledJobRun::RESULT_SUCCESS, ScheduledJobRun::RESULT_FAILED, ScheduledJobRun::RESULT_SKIPPED])
                ->selectRaw('result, count(*) as total')->groupBy('result')->pluck('total', 'result')->all();
        });
        $add('job_failed', function () use ($from, $to) {
            if (!\Schema::hasTable(ScheduledJobRun::TABLE)) {
                return [];
            }
            return ScheduledJobRun::query()->where('started_at', '>=', $from)->where('started_at', '<', $to)->where('result', ScheduledJobRun::RESULT_FAILED)
                ->selectRaw('job_key, count(*) as total')->groupBy('job_key')->pluck('total', 'job_key')->all();
        });
        return $rows;
    }

    /**
     * The users (base_user_id) who read or wrote data, or signed in, in [from, to).
     *
     * @return array<int, int>
     */
    public static function activeUsers(Carbon $from, Carbon $to): array
    {
        $ids = [];
        // the days counted into data_access_daily, and the rest from the log itself
        $summarized = DataAccessLogs::summarizedUntil();
        $rawFrom = $from->copy();
        if (DataAccessLog::tableExists() && isset($summarized) && $summarized->gte($from)) {
            $last = $summarized->copy()->addDay()->min($to);
            $ids = DataAccessDaily::query()->where('date', '>=', $from->format('Y-m-d'))->where('date', '<', $last->format('Y-m-d'))
                ->whereNotNull('base_user_id')->distinct()->pluck('base_user_id')->all();
            $rawFrom = $last;
        }
        if (DataAccessLog::tableExists() && $rawFrom->lt($to)) {
            $ids = array_merge($ids, DataAccessLog::query()->where('created_at', '>=', $rawFrom)->where('created_at', '<', $to)
                ->where('via', '<>', DataAccessLog::VIA_SYSTEM)->whereNotNull('base_user_id')->distinct()->pluck('base_user_id')->all());
        }
        if (LoginLog::tableExists()) {
            $ids = array_merge($ids, LoginLog::query()->where('result', LoginLog::RESULT_SUCCESS)->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->whereNotNull('base_user_id')->distinct()->pluck('base_user_id')->all());
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * The rows of each table: what the tuning counted tonight, else counted here.
     *
     * @return array<string, int>
     */
    protected static function tableRows(): array
    {
        $stats = DataStats::load();
        // the time of the count: seconds (or a date)
        $time = isset($stats) ? (is_numeric($stats['time']) ? Carbon::createFromTimestamp(intval($stats['time'])) : Carbon::parse(strval($stats['time']))) : null;
        if (isset($time) && $time->gt(static::now()->subHours(26)) && is_array($stats['tables'] ?? null)) {
            $rows = [];
            foreach ($stats['tables'] as $name => $table) {
                $rows[strval($name)] = intval(is_array($table) ? ($table['rows'] ?? 0) : 0);
            }
            return $rows;
        }
        $rows = [];
        foreach (\Exceedone\Exment\Model\CustomTable::allRecords() as $table) {
            try {
                $rows[strval($table->table_name)] = intval(getModelName($table)::query()->count());
            } catch (\Throwable $e) {
                // a table without its database table: skipped
            }
        }
        return $rows;
    }

    /**
     * The bytes of the attachments (what the tuning counted) and of the file server.
     *
     * @return array<string, int>
     */
    protected static function storage(): array
    {
        $bytes = [];
        $stats = DataStats::load();
        if (isset($stats) && is_array($stats['files'] ?? null)) {
            $bytes['attachments'] = intval($stats['files']['bytes'] ?? 0);
        }
        if (\Schema::hasTable('file_node_versions')) {
            $bytes['file_server'] = intval(\DB::table('file_node_versions')->sum('size'));
        }
        return $bytes;
    }

    /**
     * The changes (not the reads) of the settings, by the page.
     *
     * @return array<string, int>
     */
    protected static function adminChanges(Carbon $from, Carbon $to): array
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $prefix = $prefix === '' ? '' : $prefix . '/';
        $counts = [];
        OperationLog::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->where('method', '<>', 'GET')
            ->selectRaw('path, count(*) as total')->groupBy('path')->get()
            ->each(function ($row) use ($prefix, &$counts) {
                $path = strval($row->path);
                if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                    return;
                }
                $path = substr($path, strlen($prefix));
                foreach (static::ADMIN_PAGES as $page) {
                    if ($path === $page || str_starts_with($path, $page . '/')) {
                        $counts[$page] = ($counts[$page] ?? 0) + intval($row->total);
                        return;
                    }
                }
            });
        return $counts;
    }

    /**
     * The pages of the plugins opened, by the plugin (its URL name).
     *
     * @return array<string, int>
     */
    protected static function pluginUse(Carbon $from, Carbon $to): array
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $start = ($prefix === '' ? '' : $prefix . '/') . 'plugins/';
        $counts = [];
        OperationLog::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->where('path', 'like', $start . '%')
            ->selectRaw('path, count(*) as total')->groupBy('path')->get()
            ->each(function ($row) use ($start, &$counts) {
                $uri = explode('/', substr(strval($row->path), strlen($start)))[0];
                if ($uri !== '') {
                    $counts[$uri] = ($counts[$uri] ?? 0) + intval($row->total);
                }
            });
        return $counts;
    }

    /**
     * The calls with the users' API keys, counted on the keys: the count now less the count kept
     * the night before (api_key_total). Only the night after the day (the keys count until now).
     */
    protected static function keepApiKeyUse(Carbon $day): void
    {
        if (!$day->eq(static::now()->startOfDay()->subDay()) || !\Schema::hasTable('user_api_keys')) {
            return;
        }
        $date = $day->format('Y-m-d');
        $total = intval(UserApiKey::query()->sum('use_count'));
        $before = InsightDaily::query()->where('metric', 'api_key_total')->where('date', '<', $date)->orderByDesc('date')->value('value');
        InsightDaily::query()->where('date', $date)->whereIn('metric', ['api_key_total', 'api_key_use'])->delete();
        InsightDaily::query()->insert(['date' => $date, 'metric' => 'api_key_total', 'key' => '', 'value' => $total]);
        // the first night: no count to compare with
        if (isset($before) && $total > intval($before)) {
            InsightDaily::query()->insert(['date' => $date, 'metric' => 'api_key_use', 'key' => '', 'value' => $total - intval($before)]);
        }
    }

    /**
     * The mails of the day, sent and failed (the send log).
     *
     * @return array<string, int>
     */
    protected static function mails(Carbon $from, Carbon $to): array
    {
        $model = getModelName(SystemTableName::MAIL_SEND_LOG);
        $all = $model::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        if ($all === 0) {
            return [];
        }
        $failed = $model::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('value->send_result', \Exceedone\Exment\Notifications\Mail\MailChannel::RESULT_FAILED)->count();
        return ['sent' => $all - $failed, 'failed' => $failed];
    }

    // ============================================================ counted when it happens

    /** The metrics hit() counts (collect() leaves them). */
    public const HIT_METRICS = ['webhook_failed', 'api_key_refused', 'api_key_total', 'api_key_use'];

    /**
     * Count one now (a webhook not sent, a refused API key). Never fails the request.
     */
    public static function hit(string $metric, string $key = ''): void
    {
        try {
            if (!static::enabled()) {
                return;
            }
            $date = static::now()->format('Y-m-d');
            $key = static::key($key);
            $updated = InsightDaily::query()->where('date', $date)->where('metric', $metric)->where('key', $key)->increment('value');
            if ($updated === 0) {
                try {
                    InsightDaily::query()->insert(['date' => $date, 'metric' => $metric, 'key' => $key, 'value' => 1]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // made by another request at the same time
                    InsightDaily::query()->where('date', $date)->where('metric', $metric)->where('key', $key)->increment('value');
                }
            }
        } catch (\Throwable $e) {
            \Log::debug('[Exment] insights: ' . $e->getMessage());
        }
    }

    /** A key as kept (at most 191 characters). */
    public static function key(mixed $key): string
    {
        return mb_substr(strval($key), 0, 191);
    }
}
