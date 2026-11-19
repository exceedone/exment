<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\QueryLogger;
use Carbon\Carbon;
use Closure;

/**
 * System performance log (system setting "logging_toggle_available").
 *
 * - Only slow requests are saved: the request took "logging_slow_request_seconds" or more, or one SQL took
 *   "logging_slow_sql_seconds" or more. When both are 0, every request is saved (the behavior before v7.1).
 * - At most "logging_max_per_hour" logs are saved in an hour (0: no limit).
 * - The SQL is saved with "?" (without the values), and the values of the URL query are hidden.
 * - The log is saved after the response is sent (terminate), and an error in saving does not stop the request.
 */
class CheckLoggingEnabled
{
    /**
     * The query keys of the URL whose values are kept (they are not personal or secret data).
     */
    public const URL_SAFE_KEYS = ['view', 'page', 'per_page', '_pjax', '_sort', 'modal', 'lazy_grid_rows'];

    /**
     * What to save in terminate(): null when nothing is saved.
     *
     * @var array<string, mixed>|null
     */
    protected static $pending = null;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        static::$pending = null;
        try {
            if (System::logging_toggle_available()) {
                static::$pending = static::makeLog($request);
            }
        } catch (\Throwable $ex) {
            // the log must not stop the request
            logger($ex);
        }

        return $response;
    }

    /**
     * Save the log after the response is sent.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $response
     * @return void
     */
    public function terminate($request, $response)
    {
        $pending = static::$pending;
        static::$pending = null;
        if (is_null($pending)) {
            return;
        }

        try {
            static::saveLog($pending);
        } catch (\Throwable $ex) {
            logger($ex);
        } finally {
            QueryLogger::clear();
        }
    }

    /**
     * Make the log of the request, or null when it is not saved (not slow).
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>|null
     */
    protected static function makeLog($request): ?array
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : (float)($request->server('REQUEST_TIME_FLOAT') ?? microtime(true));
        $seconds = microtime(true) - $start;

        $slowRequest = (float)System::logging_slow_request_seconds();
        $slowSql = (float)System::logging_slow_sql_seconds();

        $isSlow = ($slowRequest <= 0 && $slowSql <= 0)
            || ($slowRequest > 0 && $seconds >= $slowRequest)
            || ($slowSql > 0 && QueryLogger::maxTime() >= $slowSql * 1000);
        if (!$isSlow) {
            QueryLogger::clear();
            return null;
        }

        $user = \Exment::user();
        $email = isset($user) && isset($user->base_user) ? $user->base_user->getValue('email') : null;

        $summary = sprintf(
            "%s: %.2fs / SQL: %d (%.2fs, max %.2fs) / memory: %.1fMB",
            'time',
            $seconds,
            QueryLogger::count(),
            QueryLogger::totalTime() / 1000,
            QueryLogger::maxTime() / 1000,
            memory_get_peak_usage(true) / 1024 / 1024
        );
        $sql = $summary . "\n" . implode("\n", QueryLogger::all());
        QueryLogger::clear();

        return [
            'email' => $email,
            'url' => static::maskUrl($request->fullUrl()),
            'create_at' => Carbon::now()->toDateTimeString(),
            'sql' => $sql,
        ];
    }

    /**
     * Save the log to the custom table "system_logs".
     *
     * @param array<string, mixed> $log
     * @return void
     */
    protected static function saveLog(array $log)
    {
        $table = CustomTable::getEloquent(SystemTableName::SYSTEM_LOGS);
        if (!isset($table)) {
            return;
        }

        // at most N logs in an hour, so that the logs do not grow too much
        $max = (int)System::logging_max_per_hour();
        if ($max > 0) {
            $count = $table->getValueModel()->withoutGlobalScopes()
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->count();
            if ($count >= $max) {
                return;
            }
        }

        $system_logs = $table->getValueModel();
        $system_logs->parent_id = null;
        $system_logs->parent_type = null;
        foreach ($log as $key => $value) {
            $system_logs->setValue($key, $value);
        }
        $system_logs->save();
    }

    /**
     * Hide the values of the URL query, except the keys that are not personal or secret data.
     *
     * @param string $url
     * @return string
     */
    public static function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['query']) || $parts['query'] === '') {
            return $url;
        }

        $query = [];
        foreach (explode('&', $parts['query']) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $key = $kv[0];
            $baseKey = preg_replace('/(%5B|\[).*$/i', '', urldecode($key));
            if (count($kv) == 1 || in_array($baseKey, static::URL_SAFE_KEYS, true)) {
                $query[] = $pair;
            } else {
                $query[] = $key . '=***';
            }
        }

        $pos = strpos($url, '?');
        $base = $pos === false ? $url : substr($url, 0, $pos);
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $base . '?' . implode('&', $query) . $fragment;
    }
}
