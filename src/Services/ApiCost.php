<?php

namespace Exceedone\Exment\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rate limit of the API and MCP in points (ISS02885 #5): a heavy call counts more than a
 * light one. The limit per minute stays the number it is (exment.api_rate_limit, the raises
 * of ISS00442, exment.mcp_calls_per_minute), read as points.
 *
 * exment.api_rate_cost (EXMENT_API_RATE_COST):
 *   - false (default): every call is 1, as before
 *   - true: the calls count their points, and a call over the limit is refused (429)
 *   - log: the calls are limited as before (1 each), and the points are counted beside: a
 *     client over the limit in points is logged (once a minute), not refused. To see what
 *     turning it on would refuse (ISS02915 #4).
 *
 * The table of the points is here only, so it can be changed after measuring.
 */
class ApiCost
{
    public const MODE_OFF = 'off';
    public const MODE_ON = 'on';
    public const MODE_LOG = 'log';

    /** the rows that add a point */
    public const ROWS_PER_POINT = 50;

    /**
     * The points of an API action, before the rows (Controller@method).
     *
     * @var array<string, int>
     */
    public const BASE = [
        // the records of a table: 1, and a point for every 50 rows
        'ApiDataController@dataList' => 1,
        'ApiDataController@dataFind' => 1,
        'ApiDataController@calendarList' => 1,
        // a search (q): one more
        'ApiDataController@dataQuery' => 2,
        'ApiDataController@dataQueryColumn' => 2,
        // the records of a view: 2, and a point for every 50 rows
        'ApiDataController@viewDataList' => 2,
        'ApiDataController@viewDataFind' => 2,
    ];

    /**
     * The actions that read rows: the rows they read add points.
     *
     * @var array<int, string>
     */
    public const ROW_ACTIONS = [
        'ApiDataController@dataList',
        'ApiDataController@calendarList',
        'ApiDataController@dataQuery',
        'ApiDataController@dataQueryColumn',
        'ApiDataController@viewDataList',
    ];

    /**
     * The actions of one record: one row.
     *
     * @var array<int, string>
     */
    public const ONE_ROW_ACTIONS = [
        'ApiDataController@dataFind',
        'ApiDataController@viewDataFind',
    ];

    public static function mode(): string
    {
        $value = config('exment.api_rate_cost', false);
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if ($value === self::MODE_LOG) {
                return self::MODE_LOG;
            }
            return in_array($value, ['1', 'true', 'on', 'yes'], true) ? self::MODE_ON : self::MODE_OFF;
        }
        return $value ? self::MODE_ON : self::MODE_OFF;
    }

    /**
     * The points of an API request.
     */
    public static function ofRequest(Request $request): int
    {
        $route = $request->route();
        $action = '';
        if ($route instanceof \Illuminate\Routing\Route) {
            // the controller of the action, else what it uses (a route made outside the router)
            $action = $route->getActionName();
            if ($action === 'Closure' && is_string($route->getAction('uses'))) {
                $action = $route->getAction('uses');
            }
        }
        $action = substr($action, strrpos($action, '\\') !== false ? strrpos($action, '\\') + 1 : 0);

        $points = static::BASE[$action] ?? 1;
        if (in_array($action, static::ROW_ACTIONS, true)) {
            $points += static::rowPoints(static::requestedRows($request));
        } elseif (in_array($action, static::ONE_ROW_ACTIONS, true)) {
            $points += static::rowPoints(1);
        }

        if (isset(static::BASE[$action])) {
            foreach (['children', 'parents'] as $key) {
                if ($request->has($key) && boolval($request->get($key))) {
                    $points++;
                }
            }
            // a list without its total (#2210) is lighter: so that clients use it
            if (in_array(strtolower(strval($request->get('total'))), ['0', 'false'], true) && $request->has('total')) {
                $points--;
            }
        }

        return max(1, $points);
    }

    /**
     * The points of an MCP message (one JSON-RPC request, or a batch of them).
     *
     * @param mixed $message
     */
    public static function ofMcpMessage($message): int
    {
        if (!is_array($message)) {
            return 1;
        }
        if (array_is_list($message)) {
            $points = 0;
            foreach ($message as $one) {
                $points += static::ofMcpMessage($one);
            }
            return max(1, $points);
        }
        if (($message['method'] ?? null) !== 'tools/call') {
            return 1;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (($params['name'] ?? null) !== 'search_data') {
            return 1;
        }
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $limit = max(1, intval($args['limit'] ?? 20));
        $points = 1 + static::rowPoints($limit);
        if (is_string($args['q'] ?? null) && trim($args['q']) !== '') {
            $points++;
        }
        return $points;
    }

    /**
     * The points the rows add: one for every 50 (20 rows = 1, 100 rows = 2).
     */
    public static function rowPoints(int $rows): int
    {
        return $rows <= 0 ? 0 : intdiv($rows + static::ROWS_PER_POINT - 1, static::ROWS_PER_POINT);
    }

    /**
     * The rows a list reads: its count, else the default, at most the maximum.
     */
    protected static function requestedRows(Request $request): int
    {
        $max = max(1, intval(config('exment.api_max_data_count', 100)));
        $count = $request->get('count');
        if (!is_numeric($count) || intval($count) < 1) {
            return min($max, max(1, intval(config('exment.api_default_data_count', 20))));
        }
        return min($max, intval($count));
    }

    /**
     * Who a log line is about: the user, else the address (as the logs keep it, log_ip_mode).
     */
    public static function whoOf(Request $request): string
    {
        $user = \Exment::user();
        if (isset($user)) {
            return '(user ' . intval($user->base_user_id ?? $user->id) . ')';
        }
        return '(from ' . strval(\Exceedone\Exment\Services\LogManagement\LogPrivacy::ip(IpAddress::limiterKey($request->ip()))) . ')';
    }

    /**
     * Log mode: count the points beside the limit, and log a client over it, once a minute.
     */
    public static function logOverLimit(string $key, int $points, int $maxPoints, int $decaySeconds, string $what): void
    {
        $shadow = 'api-cost-log:' . $key;
        $used = RateLimiter::increment($shadow, $decaySeconds, $points);
        if ($used <= $maxPoints) {
            return;
        }
        RateLimiter::attempt('api-cost-logged:' . $key, 1, function () use ($used, $maxPoints, $what) {
            Log::warning("[Exment] {$what} is over its rate limit in points ({$used} / {$maxPoints} a minute). Not refused: EXMENT_API_RATE_COST=log.");
        }, $decaySeconds);
    }
}
