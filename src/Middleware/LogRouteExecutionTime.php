<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Model\CustomTable;
use Carbon\Carbon;


use Closure;
use Illuminate\Http\Request;

class LogRouteExecutionTime
{
    protected static $queries = [];

     public static function addQuery($sql) {
        $index = count(self::$queries) + 1;
        self::$queries[] = "{$index}. {$sql}";
    }

    public static function getQueries()
    {
        return self::$queries;
    }

    public static function clearQueries()
    {
        self::$queries = [];
    }
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $log_enabled = boolval(config('exment.debugmode', false)) || boolval(config('exment.debugmode_sql', false));
        if ($log_enabled) {
            \Log::info("画面名: " . $request->fullUrl());
            $start = microtime(true);
        }
        $response = $next($request);
        if ($log_enabled) {
            $user = \Exment::user()->base_user;
            $email = $user->getValue('email');
            $url = $request->fullUrl();
            $log_date_time = Carbon::now()->toDateTimeString();
            $execution_logs = CustomTable::getEloquent('execution_logs')->getValueModel();
            $execution_logs->parent_id = null;
            $execution_logs->parent_type = null;
            $execution_logs->setValue('email', $email);
            $execution_logs->setValue('log_date_time', $log_date_time);
            $execution_logs->setValue('url', $url);
            $allSql = implode("\n", self::getQueries());
            $execution_logs->setValue('sql', $allSql);
            $execution_logs->save();
            self::clearQueries();
        }

        return $response;
    }
}
