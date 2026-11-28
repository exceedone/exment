<?php

namespace Exceedone\Exment\Services\Insights;

use Carbon\Carbon;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\McpLog;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Services\LogManagement\DataAccessLogs;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;

/**
 * "My insights" (ISS02964): the numbers of the user who looks, and of no one else (an
 * administrator sees here only their own). Read from the logs by the user (indexed by the user
 * and the time), data_access_daily, and the rows of insight_daily whose key starts with the user.
 */
class MyInsights
{
    /** A sign-in from an address not used in this many days before: a new place. */
    public const NEW_PLACE_DAYS = 90;

    public const TOP = 8;

    /** @var LoginUser */
    protected $user;

    /** @var int */
    protected $userId;

    /** @var Carbon */
    public $from;

    /** @var Carbon the last day (included) */
    public $to;

    public function __construct(LoginUser $user, Carbon $from, Carbon $to)
    {
        $this->user = $user;
        $this->userId = intval($user->base_user_id);
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->startOfDay();
    }

    protected function end(): Carbon
    {
        return $this->to->copy()->addDay();
    }

    /** @return array<int, string> */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->from->copy(); $day->lte($this->to); $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }

    /**
     * The rows of insight_daily of this user: key (after "user id|") => sum.
     *
     * @return array<string, int>
     */
    protected function userRows(string $metric): array
    {
        if (!InsightDaily::tableExists()) {
            return [];
        }
        $prefix = $this->userId . '|';
        $sums = [];
        InsightDaily::query()->where('metric', $metric)->where('date', '>=', $this->from->format('Y-m-d'))->where('date', '<=', $this->to->format('Y-m-d'))
            ->where('key', 'like', $prefix . '%')->get(['key', 'value'])
            ->each(function ($row) use ($prefix, &$sums) {
                $key = substr(strval($row->key), strlen($prefix));
                $sums[$key] = ($sums[$key] ?? 0) + intval($row->value);
            });
        arsort($sums);
        return $sums;
    }

    /**
     * My sign-ins: by the day, by the hour, the failures, the sign-ins from a new place.
     *
     * @return array<string, mixed>
     */
    public function logins(): array
    {
        $days = array_fill_keys($this->dates(), 0);
        $hours = array_fill(0, 24, 0);
        $failures = [];
        $newPlaces = [];
        if (LoginLog::tableExists()) {
            $mine = LoginLog::query()->where('base_user_id', $this->userId)->where('result', LoginLog::RESULT_SUCCESS)
                ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->end())->orderBy('created_at')->get(['created_at', 'ip', 'user_agent']);
            foreach ($mine as $row) {
                $date = $row->created_at->format('Y-m-d');
                if (array_key_exists($date, $days)) {
                    $days[$date]++;
                }
                $hours[intval($row->created_at->format('G'))]++;
            }
            // a place (address) not used in the days before
            $seen = LoginLog::query()->where('base_user_id', $this->userId)->where('result', LoginLog::RESULT_SUCCESS)
                ->where('created_at', '>=', $this->from->copy()->subDays(static::NEW_PLACE_DAYS))->where('created_at', '<', $this->from)
                ->whereNotNull('ip')->distinct()->pluck('ip')->map(function ($ip) {
                    return strval($ip);
                })->all();
            $seen = array_flip($seen);
            foreach ($mine as $row) {
                $ip = strval($row->ip);
                if ($ip === '' || isset($seen[$ip])) {
                    continue;
                }
                $seen[$ip] = true;
                $newPlaces[] = ['at' => $row->created_at->format('Y-m-d H:i'), 'ip' => $ip, 'agent' => static::agent(strval($row->user_agent))];
            }
            // the failures with my name or my login
            $failures = LoginLog::query()->whereIn('result', [LoginLog::RESULT_FAILURE, LoginLog::RESULT_LOCKOUT])
                ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->end())
                ->where(function ($query) {
                    // the login, or the names I sign in with (what was typed is kept when the password was wrong)
                    $names = array_values(array_filter([strval($this->user->user_code ?? ''), strval($this->user->email ?? '')]));
                    $query->where('login_user_id', $this->user->id);
                    if (count($names) > 0) {
                        $query->orWhereIn('login_name', $names);
                    }
                })->orderByDesc('created_at')->limit(20)->get(['created_at', 'result', 'ip'])
                ->map(function ($row) {
                    return ['at' => $row->created_at->format('Y-m-d H:i'), 'result' => strval($row->result), 'ip' => strval($row->ip)];
                })->all();
        }
        return [
            'days' => $days,
            'days_in' => count(array_filter($days)),
            'hours' => $hours,
            'failures' => $failures,
            'new_places' => array_slice(array_reverse($newPlaces), 0, 10),
        ];
    }

    /** A short name of a browser and its system. */
    protected static function agent(string $agent): string
    {
        $browser = preg_match('/Edg\//', $agent) ? 'Edge' : (preg_match('/Chrome\//', $agent) ? 'Chrome' : (preg_match('/Firefox\//', $agent) ? 'Firefox' : (preg_match('/Safari\//', $agent) ? 'Safari' : '')));
        $os = preg_match('/Windows/', $agent) ? 'Windows' : (preg_match('/iPhone|iPad/', $agent) ? 'iOS' : (preg_match('/Android/', $agent) ? 'Android' : (preg_match('/Mac OS X/', $agent) ? 'macOS' : (preg_match('/Linux/', $agent) ? 'Linux' : ''))));
        return trim($browser . ' ' . $os);
    }

    /**
     * My use of the data: the tables I read most, the views I open most, the rows I made and changed.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $tables = [];
        $add = function ($tableId, $kind, $count) use (&$tables) {
            $tableId = intval($tableId);
            $tables[$tableId] = $tables[$tableId] ?? ['read' => 0, 'create' => 0, 'update' => 0];
            if ($kind === DataAccessLog::KIND_CREATE) {
                $tables[$tableId]['create'] += intval($count);
            } elseif ($kind === DataAccessLog::KIND_UPDATE) {
                $tables[$tableId]['update'] += intval($count);
            } elseif (in_array($kind, DataAccessLog::READS, true)) {
                $tables[$tableId]['read'] += intval($count);
            }
        };
        if (DataAccessLog::tableExists()) {
            $summarized = DataAccessLogs::summarizedUntil();
            $rawFrom = $this->from->copy();
            if (isset($summarized) && $summarized->gte($this->from)) {
                $dailyTo = $summarized->copy()->min($this->to);
                DataAccessDaily::query()->where('base_user_id', $this->userId)->where('via', '<>', DataAccessLog::VIA_SYSTEM)
                    ->where('date', '>=', $this->from->format('Y-m-d'))->where('date', '<=', $dailyTo->format('Y-m-d'))
                    ->selectRaw('custom_table_id, kind, sum(count) as total')->groupBy('custom_table_id', 'kind')->get()
                    ->each(function ($row) use ($add) {
                        $add($row->custom_table_id, $row->kind, $row->total);
                    });
                $rawFrom = $dailyTo->copy()->addDay();
            }
            DataAccessLog::query()->where('base_user_id', $this->userId)->where('via', '<>', DataAccessLog::VIA_SYSTEM)
                ->where('created_at', '>=', $rawFrom)->where('created_at', '<', $this->end())
                ->selectRaw('custom_table_id, kind, count(*) as total')->groupBy('custom_table_id', 'kind')->get()
                ->each(function ($row) use ($add) {
                    $add($row->custom_table_id, $row->kind, $row->total);
                });
        }
        $rows = [];
        foreach ($tables as $id => $counts) {
            $table = CustomTable::getEloquent($id);
            if (!isset($table)) {
                continue;
            }
            $rows[] = ['name' => strval($table->table_name), 'label' => strval($table->table_view_name)] + $counts;
        }
        $byRead = $rows;
        usort($byRead, function ($a, $b) {
            return $b['read'] <=> $a['read'];
        });
        $byWrite = array_values(array_filter($rows, function ($row) {
            return $row['create'] + $row['update'] > 0;
        }));
        usort($byWrite, function ($a, $b) {
            return ($b['create'] + $b['update']) <=> ($a['create'] + $a['update']);
        });
        $views = [];
        foreach (array_slice($this->userRows('user_view'), 0, static::TOP, true) as $id => $count) {
            $view = CustomView::find(intval($id));
            if (isset($view) && isset($view->custom_table)) {
                $views[] = ['label' => strval($view->view_view_name), 'table' => strval($view->custom_table->table_view_name), 'count' => $count,
                    'url' => admin_urls('data', $view->custom_table->table_name) . '?view=' . $view->suuid];
            }
        }
        return [
            'tables' => array_slice(array_values(array_filter($byRead, function ($row) {
                return $row['read'] > 0;
            })), 0, static::TOP),
            'written' => array_slice($byWrite, 0, static::TOP),
            'created' => array_sum(array_column($rows, 'create')),
            'updated' => array_sum(array_column($rows, 'update')),
            'views' => $views,
        ];
    }

    /** @var array<string, callable> the cards other code adds (registerCard()) */
    protected static $cards = [];

    /**
     * Add a card to "My insights" (an application, a package). A plugin page may instead have a
     * method myInsightsCard(LoginUser $user, Carbon $from, Carbon $to). Both return the card of
     * the user who looks, or null for none:
     * ['title' => string, 'rows' => [['label' => string, 'value' => string|int|float, 'warn' => bool], ...],
     *  'url' => a link (a path of the site or http(s)), 'url_label' => string, 'help' => string]
     *
     * @param callable $card function (LoginUser $user, Carbon $from, Carbon $to): ?array
     */
    public static function registerCard(string $key, callable $card): void
    {
        static::$cards[$key] = $card;
    }

    /** Forget the cards added (tests). */
    public static function resetCards(): void
    {
        static::$cards = [];
    }

    /**
     * The cards of the plugins and of the code that added one, for this user and period.
     *
     * @return array<int, array{title: string, rows: array<int, array{label: string, value: string, warn: bool}>, url: string|null, url_label: string|null, help: string|null}>
     */
    public function extraCards(): array
    {
        $sources = static::$cards;
        try {
            foreach (\Exceedone\Exment\Model\Plugin::getPluginPages() as $page) {
                if (is_object($page) && method_exists($page, 'myInsightsCard')) {
                    $sources[get_class($page)] = [$page, 'myInsightsCard'];
                }
            }
        } catch (\Throwable $e) {
            \Log::debug('[Exment] my insights: the plugins: ' . $e->getMessage());
        }
        $cards = [];
        foreach ($sources as $key => $source) {
            try {
                $card = call_user_func($source, $this->user, $this->from->copy(), $this->to->copy());
            } catch (\Throwable $e) {
                \Log::warning('[Exment] my insights: the card ' . $key . ': ' . $e->getMessage());
                continue;
            }
            $card = static::card($card);
            if (isset($card)) {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    /**
     * A card as the page shows it: text only, a link of the site or http(s).
     *
     * @return array{title: string, rows: array<int, array{label: string, value: string, warn: bool}>, url: string|null, url_label: string|null, help: string|null}|null
     */
    protected static function card(mixed $card): ?array
    {
        if (!is_array($card) || !is_string($card['title'] ?? null) || trim($card['title']) === '') {
            return null;
        }
        $rows = [];
        foreach (array_slice((array)($card['rows'] ?? []), 0, 20) as $row) {
            if (!is_array($row) || !is_scalar($row['label'] ?? null) || !is_scalar($row['value'] ?? null)) {
                continue;
            }
            $rows[] = ['label' => mb_substr(strval($row['label']), 0, 100), 'value' => mb_substr(strval($row['value']), 0, 50), 'warn' => boolval($row['warn'] ?? false)];
        }
        $url = is_string($card['url'] ?? null) ? $card['url'] : null;
        // a link of the site (a path) or http(s); no quote, no space, not "//host"
        if (isset($url) && preg_match('#\A(https?://[^\s"\'<>]+|/[^/\s"\'<>][^\s"\'<>]*)\z#i', $url) !== 1) {
            $url = null;
        }
        return [
            'title' => mb_substr(trim($card['title']), 0, 100),
            'rows' => $rows,
            'url' => $url,
            'url_label' => is_string($card['url_label'] ?? null) ? mb_substr($card['url_label'], 0, 50) : null,
            'help' => is_string($card['help'] ?? null) ? mb_substr($card['help'], 0, 300) : null,
        ];
    }

    /**
     * The approvals waiting for me, and my requests not finished.
     *
     * @return array{waiting: int, requests: int, url: string}|null
     */
    public function workflow(): ?array
    {
        try {
            $waiting = (new WorkflowTaskService())->countAll();
            $completed = WorkflowStatus::query()->where('completed_flg', 1)->pluck('id')->all();
            // the requests I started (the first action, from no status)
            $started = WorkflowValue::query()->where('created_user_id', $this->userId)->whereNull('workflow_status_from_id')->get(['morph_type', 'morph_id']);
            $open = 0;
            foreach ($started->groupBy('morph_type') as $type => $values) {
                $open += WorkflowValue::query()->where('latest_flg', 1)->where('morph_type', $type)->whereIn('morph_id', $values->pluck('morph_id')->all())
                    ->where(function ($query) use ($completed) {
                        $query->whereNull('workflow_status_to_id')->orWhereNotIn('workflow_status_to_id', $completed ?: [0]);
                    })->count();
            }
        } catch (\Throwable $e) {
            \Log::debug('[Exment] my insights: workflow: ' . $e->getMessage());
            return null;
        }
        return ['waiting' => $waiting, 'requests' => $open, 'url' => admin_url('workflow_task')];
    }

    /**
     * My files on the file server: what I uploaded, shared, and opened most.
     *
     * @return array<string, mixed>|null
     */
    public function files(): ?array
    {
        if (!\Schema::hasTable('file_audit_logs')) {
            return null;
        }
        $actions = \DB::table('file_audit_logs')->where('user_id', $this->userId)->where('created_at', '>=', $this->from)->where('created_at', '<', $this->end())
            ->selectRaw('action, count(*) as total')->groupBy('action')->pluck('total', 'action')->all();
        $top = [];
        foreach (array_slice($this->userRows('user_file'), 0, static::TOP, true) as $nodeId => $count) {
            $node = \DB::table('file_nodes')->where('id', intval($nodeId))->whereNull('deleted_at')->first(['id', 'name', 'workspace_id']);
            if (isset($node)) {
                $top[] = ['name' => strval($node->name), 'count' => $count, 'url' => admin_urls('file_server', $node->workspace_id, 'nodes', $node->id)];
            }
        }
        $links = \Schema::hasTable('file_share_links')
            ? intval(\DB::table('file_share_links')->where('created_user_id', $this->userId)->whereNull('revoked_at')
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', Insights::now());
                })->count())
            : 0;
        return [
            'opened' => intval($actions['view'] ?? 0) + intval($actions['download'] ?? 0),
            'uploaded' => intval($actions['upload'] ?? 0) + intval($actions['version'] ?? 0),
            'shared' => intval($actions['share_create'] ?? 0),
            'links' => $links,
            'top' => $top,
            'url' => admin_url('file_server'),
        ];
    }

    /**
     * My API keys and the AI (MCP) as me.
     *
     * @return array<string, mixed>
     */
    public function api(): array
    {
        $keys = [];
        if (\Schema::hasTable('user_api_keys')) {
            $keys = UserApiKey::query()->where('user_id', $this->userId)->whereNull('revoked_at')->where('expires_at', '>', Insights::now())->orderByDesc('last_used_at')
                ->get(['name', 'use_count', 'last_used_at', 'last_refused_at', 'last_refused_reason', 'expires_at', 'created_at', 'allowed_ips'])
                ->map(function (UserApiKey $key) {
                    // the marks of the IT audit report, for my keys: usable from any address, not used, a long end date
                    $marks = [];
                    if (count($key->ipList()) === 0) {
                        $marks[] = 'any_ip';
                    }
                    $used = $key->last_used_at ?? $key->created_at;
                    if (isset($used) && Carbon::parse($used)->lt(Insights::now()->subDays(InsightSecurity::UNUSED_DAYS))) {
                        $marks[] = 'unused';
                    }
                    if (isset($key->created_at) && Carbon::parse($key->expires_at)->gt(Carbon::parse($key->created_at)->addDays(\Exceedone\Exment\Services\AuditReport\AuditReport::LONG_KEY_DAYS))) {
                        $marks[] = 'long_expiry';
                    }
                    return [
                        'name' => strval($key->name), 'uses' => intval($key->use_count),
                        'last_used' => isset($key->last_used_at) ? Carbon::parse($key->last_used_at)->format('Y-m-d H:i') : null,
                        'refused' => isset($key->last_refused_at) ? Carbon::parse($key->last_refused_at)->format('Y-m-d H:i') . ' (' . exmtrans('api_key.refusals.' . $key->last_refused_reason) . ')' : null,
                        'expires' => Carbon::parse($key->expires_at)->format('Y-m-d'),
                        'marks' => $marks,
                    ];
                })->all();
        }
        $mcp = ['ok' => 0, 'error' => 0];
        $tools = [];
        if (McpLog::tableExists()) {
            foreach (McpLog::query()->where('base_user_id', $this->userId)->where('created_at', '>=', $this->from)->where('created_at', '<', $this->end())
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $count) {
                $mcp[$status === McpLog::STATUS_OK ? 'ok' : 'error'] += intval($count);
            }
            $tools = McpLog::query()->where('base_user_id', $this->userId)->whereNotNull('tool')->where('created_at', '>=', $this->from)->where('created_at', '<', $this->end())
                ->selectRaw('tool, count(*) as total')->groupBy('tool')->orderByDesc('total')->limit(static::TOP)->pluck('total', 'tool')
                ->map(function ($v) {
                    return intval($v);
                })->all();
        }
        return ['keys' => $keys, 'mcp' => $mcp, 'tools' => $tools, 'url' => admin_url('auth/api_keys')];
    }

    /**
     * My sessions (browsers signed in now), when the sessions are kept in the database: the
     * address, the browser and the last time. Null when the site keeps them elsewhere (files,
     * Redis...): they cannot be listed. Never the id of a session.
     *
     * @return array<int, array{ip: string, agent: string, last: string, current: bool}>|null
     */
    public function sessions(): ?array
    {
        if (strval(config('session.driver')) !== 'database') {
            return null;
        }
        $table = strval(config('session.table', 'sessions'));
        try {
            $connection = \DB::connection(config('session.connection'));
            if (!$connection->getSchemaBuilder()->hasTable($table)) {
                return null;
            }
            $current = session()->getId();
            return $connection->table($table)->where('user_id', $this->user->id)->orderByDesc('last_activity')->limit(20)
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(function ($row) use ($current) {
                    return [
                        'ip' => strval($row->ip_address),
                        'agent' => static::agent(strval($row->user_agent)),
                        'last' => Carbon::createFromTimestamp(intval($row->last_activity))->setTimezone(strval(config('app.timezone')))->format('Y-m-d H:i'),
                        'current' => hash_equals(strval($current), strval($row->id)),
                    ];
                })->all();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
