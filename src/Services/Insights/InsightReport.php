<?php

namespace Exceedone\Exment\Services\Insights;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\LogManagement\DataAccessLogs;
use Exceedone\Exment\Services\QueueWorker;

/**
 * What the page "Insights" shows for a period (ISS02964): read from insight_daily and
 * data_access_daily (both made every night), and today counted when it is cheap.
 * The names of the people only when $perPerson (Audit); the others see the totals.
 */
class InsightReport
{
    /** The periods of the page (days). */
    public const PERIODS = [7, 30, 90, 365];

    public const DEFAULT_PERIOD = 30;

    /** No activity in this many days: an inactive user. */
    public const INACTIVE_DAYS = 30;

    /** The rows of a list (the most used tables...). */
    public const TOP = 10;

    /** @var Carbon */
    public $from;

    /** @var Carbon the last day (included) */
    public $to;

    /** @var bool */
    public $perPerson;

    /** @var array<string, array<string, array<string, int>>>|null date => metric => key => value */
    protected $rows;

    public function __construct(Carbon $from, Carbon $to, bool $perPerson)
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->startOfDay();
        $this->perPerson = $perPerson;
    }

    /**
     * The period of a request: ?days=30, or ?from=Y-m-d&to=Y-m-d (at most 2 years).
     */
    public static function fromRequest(\Illuminate\Http\Request $request, bool $perPerson): self
    {
        $today = Insights::now()->startOfDay();
        $parse = function ($value) {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        };
        $from = $parse($request->query('from'));
        $to = $parse($request->query('to'));
        if (isset($from) && isset($to) && $from->lte($to)) {
            $to = $to->min($today);
            $from = $from->max($to->copy()->subYears(2));
            return new self($from, $to, $perPerson);
        }
        $days = intval($request->query('days', static::DEFAULT_PERIOD));
        if (!in_array($days, static::PERIODS, true)) {
            $days = static::DEFAULT_PERIOD;
        }
        return new self($today->copy()->subDays($days - 1), $today, $perPerson);
    }

    /**
     * The days of the period, Y-m-d.
     *
     * @return array<int, string>
     */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->from->copy(); $day->lte($this->to); $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }

    public function includesToday(): bool
    {
        return $this->to->gte(Insights::now()->startOfDay());
    }

    /**
     * The kept rows of the period, and today counted now.
     *
     * @return array<string, array<string, array<string, int>>>
     */
    protected function rows(): array
    {
        if (isset($this->rows)) {
            return $this->rows;
        }
        $rows = [];
        InsightDaily::query()->where('date', '>=', $this->from->format('Y-m-d'))->where('date', '<=', $this->to->format('Y-m-d'))
            ->get()->each(function (InsightDaily $row) use (&$rows) {
                $rows[$row->date->format('Y-m-d')][$row->metric][$row->key] = intval($row->value);
            });
        if ($this->includesToday()) {
            $today = Insights::now()->format('Y-m-d');
            $live = \Cache::remember('exment_insights_today_' . $today, 300, function () {
                return Insights::day(Insights::now()->startOfDay(), true);
            });
            foreach ((array)$live as $metric => $values) {
                $rows[$today][$metric] = (array)$values + ($rows[$today][$metric] ?? []);
            }
        }
        return $this->rows = $rows;
    }

    /**
     * A metric by the day: date => the sum of its keys (or of the keys given). Today is null for a
     * metric counted only at night (the chart leaves a gap, not a fall to 0).
     *
     * @param array<int, string>|null $keys
     * @return array<string, int|null>
     */
    public function series(string $metric, ?array $keys = null): array
    {
        $rows = $this->rows();
        $series = [];
        $today = Insights::now()->format('Y-m-d');
        foreach ($this->dates() as $date) {
            if ($date === $today && !in_array($metric, Insights::LIVE, true) && !in_array($metric, Insights::HIT_METRICS, true)) {
                $series[$date] = null;
                continue;
            }
            $values = $rows[$date][$metric] ?? [];
            if (isset($keys)) {
                $values = array_intersect_key($values, array_flip($keys));
            }
            $series[$date] = array_sum($values);
        }
        return $series;
    }

    /**
     * A metric by its key, summed over the period, the largest first.
     *
     * @return array<string, int>
     */
    public function byKey(string $metric, ?int $limit = null): array
    {
        $sums = [];
        foreach ($this->rows() as $metrics) {
            foreach ($metrics[$metric] ?? [] as $key => $value) {
                $sums[strval($key)] = ($sums[strval($key)] ?? 0) + $value;
            }
        }
        arsort($sums);
        return isset($limit) ? array_slice($sums, 0, $limit, true) : $sums;
    }

    /**
     * The last value of a metric in the period by its key (a count of the night: the rows of the tables).
     *
     * @return array{first: array<string, int>, last: array<string, int>}
     */
    public function firstAndLast(string $metric): array
    {
        $first = null;
        $last = null;
        foreach ($this->dates() as $date) {
            $values = $this->rows()[$date][$metric] ?? null;
            if (!is_array($values) || count($values) === 0) {
                continue;
            }
            $first = $first ?? $values;
            $last = $values;
        }
        return ['first' => $first ?? [], 'last' => $last ?? []];
    }

    // ============================================================ the sections

    /**
     * The users: active a day, a week, a month; who has done nothing for INACTIVE_DAYS days.
     *
     * @return array<string, mixed>
     */
    public function users(): array
    {
        $day = $this->series('active_day');
        $week = $this->series('active_week');
        $month = $this->series('active_month');
        $total = static::userCount();
        $since = Insights::now()->startOfDay()->subDays(static::INACTIVE_DAYS - 1);
        $active = Insights::activeUsers($since, Insights::now()->startOfDay()->addDay());
        $inactive = [];
        if ($this->perPerson) {
            $model = getModelName(SystemTableName::USER);
            $inactive = $model::query()->whereNotIn('id', $active ?: [0])->orderBy('id')->limit(100)->get()
                ->map(function ($user) {
                    return ['id' => intval($user->id), 'name' => strval($user->getLabel()), 'last' => static::lastActive(intval($user->id))];
                })->all();
        }
        return [
            'series' => ['day' => $day, 'week' => $week, 'month' => $month],
            // the last day counted (today has only the day)
            'now' => ['day' => static::last($day), 'week' => static::last($week), 'month' => static::last($month)],
            'total' => $total,
            'inactive_count' => max(0, $total - count($active)),
            'inactive' => $inactive,
        ];
    }

    /**
     * The last number of a series that is not null.
     *
     * @param array<string, int|null> $series
     */
    protected static function last(array $series): int
    {
        $values = array_values(array_filter($series, function ($value) {
            return !is_null($value);
        }));
        return count($values) > 0 ? intval(end($values)) : 0;
    }

    protected static function userCount(): int
    {
        return intval(getModelName(SystemTableName::USER)::query()->count());
    }

    /** The last day a user read or wrote data or signed in (Y-m-d), null when never in the logs. */
    protected static function lastActive(int $userId): ?string
    {
        $dates = [];
        if (DataAccessLog::tableExists()) {
            $dates[] = DataAccessDaily::query()->where('base_user_id', $userId)->max('date');
            $dates[] = DataAccessLog::query()->where('base_user_id', $userId)->max('created_at');
        }
        if (LoginLog::tableExists()) {
            $dates[] = LoginLog::query()->where('base_user_id', $userId)->where('result', LoginLog::RESULT_SUCCESS)->max('created_at');
        }
        $dates = array_filter(array_map(function ($date) {
            return is_null($date) ? null : substr(strval($date), 0, 10);
        }, $dates));
        return count($dates) > 0 ? max($dates) : null;
    }

    /**
     * The tables and the views: the most and the least used, reads and writes, by people
     * (not the system), from the counts of the days (and the log for the days not counted yet).
     *
     * @return array<string, mixed>
     */
    public function tables(): array
    {
        $uses = $this->tableUses();
        // the tables of the site (not the ones of Exment itself, as the mail templates; the users and organizations stay)
        $tables = CustomTable::allRecords(function ($table) {
            return !boolval($table->system_flg) || in_array($table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION], true);
        });
        $rows = [];
        foreach ($tables as $table) {
            $use = $uses[intval($table->id)] ?? ['read' => 0, 'write' => 0, 'api' => 0];
            $rows[] = ['id' => intval($table->id), 'name' => strval($table->table_name), 'label' => strval($table->table_view_name)] + $use + ['total' => $use['read'] + $use['write']];
        }
        usort($rows, function ($a, $b) {
            return $b['total'] <=> $a['total'] ?: strcmp($a['label'], $b['label']);
        });
        $views = [];
        foreach ($this->byKey('view_use', static::TOP) as $id => $count) {
            $view = CustomView::find(intval($id));
            if (isset($view)) {
                $views[] = ['id' => intval($id), 'label' => strval($view->view_view_name), 'table' => strval(optional($view->custom_table)->table_view_name), 'count' => $count,
                    'url' => isset($view->custom_table) ? admin_urls('data', $view->custom_table->table_name) . '?view=' . $view->suuid : null];
            }
        }
        $least = array_reverse($rows);
        return [
            'most' => array_slice($rows, 0, static::TOP),
            'least' => array_slice($least, 0, static::TOP),
            'views' => $views,
            'people' => $this->perPerson ? $this->topPeople() : [],
        ];
    }

    /**
     * Reads and writes by the table in the period (people, the API and MCP; not the system).
     *
     * @return array<int, array{read: int, write: int, api: int}>
     */
    protected function tableUses(): array
    {
        if (!DataAccessLog::tableExists()) {
            return [];
        }
        $uses = [];
        $add = function ($tableId, $kind, $via, $count) use (&$uses) {
            $tableId = intval($tableId);
            $uses[$tableId] = $uses[$tableId] ?? ['read' => 0, 'write' => 0, 'api' => 0];
            if (in_array($kind, DataAccessLog::WRITES, true)) {
                $uses[$tableId]['write'] += intval($count);
            } else {
                $uses[$tableId]['read'] += intval($count);
            }
            if (in_array($via, [DataAccessLog::VIA_API, DataAccessLog::VIA_MCP], true)) {
                $uses[$tableId]['api'] += intval($count);
            }
        };
        [$dailyTo, $rawFrom] = $this->splitAtSummary();
        if (isset($dailyTo)) {
            DataAccessDaily::query()->where('date', '>=', $this->from->format('Y-m-d'))->where('date', '<=', $dailyTo->format('Y-m-d'))
                ->where('via', '<>', DataAccessLog::VIA_SYSTEM)
                ->selectRaw('custom_table_id, kind, via, sum(count) as total')->groupBy('custom_table_id', 'kind', 'via')->get()
                ->each(function ($row) use ($add) {
                    $add($row->custom_table_id, $row->kind, $row->via, $row->total);
                });
        }
        DataAccessLog::query()->where('created_at', '>=', $rawFrom)->where('created_at', '<', $this->to->copy()->addDay())
            ->where('via', '<>', DataAccessLog::VIA_SYSTEM)
            ->selectRaw('custom_table_id, kind, via, count(*) as total')->groupBy('custom_table_id', 'kind', 'via')->get()
            ->each(function ($row) use ($add) {
                $add($row->custom_table_id, $row->kind, $row->via, $row->total);
            });
        return $uses;
    }

    /**
     * The last day of data_access_daily in the period (null: none), and the time the log is read from.
     *
     * @return array{0: Carbon|null, 1: Carbon}
     */
    protected function splitAtSummary(): array
    {
        $summarized = DataAccessLogs::summarizedUntil();
        if (!isset($summarized) || $summarized->lt($this->from)) {
            return [null, $this->from->copy()];
        }
        $dailyTo = $summarized->copy()->min($this->to);
        return [$dailyTo, $dailyTo->copy()->addDay()];
    }

    /**
     * Who read and wrote the most (Audit only).
     *
     * @return array<int, array{id: int, name: string, count: int}>
     */
    protected function topPeople(): array
    {
        if (!DataAccessLog::tableExists()) {
            return [];
        }
        $counts = [];
        [$dailyTo, $rawFrom] = $this->splitAtSummary();
        if (isset($dailyTo)) {
            foreach (DataAccessDaily::query()->where('date', '>=', $this->from->format('Y-m-d'))->where('date', '<=', $dailyTo->format('Y-m-d'))
                ->where('via', '<>', DataAccessLog::VIA_SYSTEM)->whereNotNull('base_user_id')
                ->selectRaw('base_user_id, sum(count) as total')->groupBy('base_user_id')->pluck('total', 'base_user_id') as $id => $count) {
                $counts[intval($id)] = ($counts[intval($id)] ?? 0) + intval($count);
            }
        }
        foreach (DataAccessLog::query()->where('created_at', '>=', $rawFrom)->where('created_at', '<', $this->to->copy()->addDay())
            ->where('via', '<>', DataAccessLog::VIA_SYSTEM)->whereNotNull('base_user_id')
            ->selectRaw('base_user_id, count(*) as total')->groupBy('base_user_id')->pluck('total', 'base_user_id') as $id => $count) {
            $counts[intval($id)] = ($counts[intval($id)] ?? 0) + intval($count);
        }
        arsort($counts);
        $people = [];
        foreach (array_slice($counts, 0, static::TOP, true) as $id => $count) {
            $people[] = ['id' => $id, 'name' => static::userName($id), 'count' => $count];
        }
        return $people;
    }

    protected static function userName(int $id): string
    {
        $user = getModelName(SystemTableName::USER)::find($id);
        return isset($user) ? strval($user->getLabel()) : ('#' . $id);
    }

    /**
     * The growth: the rows of the tables and the bytes of the files, counted each night.
     *
     * @return array<string, mixed>
     */
    public function growth(): array
    {
        $rows = $this->firstAndLast('table_rows');
        $labels = CustomTable::allRecords()->mapWithKeys(function ($table) {
            return [strval($table->table_name) => strval($table->table_view_name)];
        })->all();
        $grown = [];
        foreach ($rows['last'] as $name => $last) {
            $first = $rows['first'][$name] ?? 0;
            $grown[] = ['name' => $name, 'label' => $labels[$name] ?? $name, 'rows' => $last, 'added' => $last - $first];
        }
        usort($grown, function ($a, $b) {
            return $b['added'] <=> $a['added'] ?: $b['rows'] <=> $a['rows'];
        });
        return [
            'rows' => $this->series('table_rows'),
            'tables' => array_slice($grown, 0, static::TOP),
            'storage' => [
                'attachments' => $this->series('storage', ['attachments']),
                'file_server' => $this->series('storage', ['file_server']),
            ],
        ];
    }

    /**
     * The sign-ins and the changes of the settings and of the permissions.
     *
     * @return array<string, mixed>
     */
    public function security(): array
    {
        $failures = [];
        if ($this->perPerson && LoginLog::tableExists()) {
            $failures = LoginLog::query()->whereIn('result', [LoginLog::RESULT_FAILURE, LoginLog::RESULT_LOCKOUT])
                ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to->copy()->addDay())
                ->selectRaw('login_name, count(*) as total')->groupBy('login_name')->orderByDesc('total')->limit(static::TOP)
                ->pluck('total', 'login_name')->map(function ($v) { return intval($v); })->all();
        }
        return [
            'login' => [
                'success' => $this->series('login', [LoginLog::RESULT_SUCCESS]),
                'failure' => $this->series('login', [LoginLog::RESULT_FAILURE]),
                'lockout' => $this->series('login', [LoginLog::RESULT_LOCKOUT]),
            ],
            'permission_change' => $this->series('permission_change'),
            'admin_change' => $this->series('admin_change'),
            'admin_pages' => $this->byKey('admin_change', static::TOP),
            'failures' => $failures,
        ];
    }

    /**
     * The API keys and MCP.
     *
     * @return array<string, mixed>
     */
    public function api(): array
    {
        return [
            'api_key_use' => $this->series('api_key_use'),
            'api_key_refused' => $this->series('api_key_refused'),
            'refusals' => $this->byKey('api_key_refused', static::TOP),
            'mcp_ok' => $this->series('mcp', ['ok']),
            'mcp_error' => $this->series('mcp', array_values(array_diff(array_keys($this->byKey('mcp')), ['ok']))),
            'tools' => $this->byKey('mcp_tool', static::TOP),
        ];
    }

    /**
     * The mails and the webhooks.
     *
     * @return array<string, mixed>
     */
    public function delivery(): array
    {
        return [
            'mail_sent' => $this->series('mail', ['sent']),
            'mail_failed' => $this->series('mail', ['failed']),
            'webhook_failed' => $this->series('webhook_failed'),
            'webhook_hosts' => $this->byKey('webhook_failed', static::TOP),
        ];
    }

    /**
     * What the browsers told: the errors and the slow pages.
     *
     * @return array<string, mixed>
     */
    public function browser(): array
    {
        return [
            'error' => $this->series('browser', ['error']),
            'request' => $this->series('browser', ['request']),
            'errors' => $this->byKey('browser_error', static::TOP),
            'slow' => $this->byKey('slow_page', static::TOP),
        ];
    }

    /**
     * The scheduled jobs and the queue.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $failedJobs = null;
        try {
            $table = strval(config('queue.failed.table', 'failed_jobs'));
            if ($table !== '' && \Schema::hasTable($table)) {
                $failedJobs = intval(\DB::table($table)->where('failed_at', '>=', $this->from)->where('failed_at', '<', $this->to->copy()->addDay())->count());
            }
        } catch (\Throwable $e) {
            $failedJobs = null;
        }
        return [
            'success' => $this->series('job', ['success']),
            'failed' => $this->series('job', ['failed']),
            'jobs' => $this->byKey('job_failed', static::TOP),
            'queue' => QueueWorker::state(),
            'failed_jobs' => $failedJobs,
        ];
    }

    /**
     * The features in use and the plugins.
     *
     * @return array<string, mixed>
     */
    public function features(): array
    {
        $features = [];
        foreach (Features::definitions() as $key => $definition) {
            $features[] = ['key' => $key, 'label' => strval(trans($definition['label'])), 'enabled' => Features::enabled($key)];
        }
        $uses = $this->byKey('plugin_use');
        $plugins = [];
        foreach (Plugin::query()->get() as $plugin) {
            $uri = strval($plugin->getOptionUri());
            $plugins[] = ['label' => strval($plugin->plugin_view_name ?: $plugin->plugin_name), 'active' => boolval($plugin->active_flg), 'count' => intval($uses[$uri] ?? 0)];
        }
        usort($plugins, function ($a, $b) {
            return $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']);
        });
        return ['features' => $features, 'plugins' => $plugins];
    }

    /**
     * All the numbers of the period, one row each (the CSV).
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: int}>
     */
    public function csvRows(): array
    {
        $out = [];
        foreach ($this->rows() as $date => $metrics) {
            foreach ($metrics as $metric => $values) {
                if (in_array($metric, ['api_key_total'], true)) {
                    continue;
                }
                foreach ($values as $key => $value) {
                    $out[] = [strval($date), strval($metric), strval($key), intval($value)];
                }
            }
        }
        usort($out, function ($a, $b) {
            return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
        });
        return $out;
    }
}
