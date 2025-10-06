<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Model\CustomTable;
use Carbon\Carbon;


use Closure;
use Exceedone\Exment\Services\QueryLogger;
use Illuminate\Http\Request;

class LogRouteExecutionTime
{
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
            $date_time = Carbon::now()->toDateTimeString();
            $execution_logs = CustomTable::getEloquent('execution_logs')->getValueModel();
            $execution_logs->parent_id = null;
            $execution_logs->parent_type = null;
            $execution_logs->setValue('email', $email);
            $execution_logs->setValue('create_at', $date_time);
            $execution_logs->setValue('url', $url);
            $allSql = implode("\n", QueryLogger::all());
            $execution_logs->setValue('sql', $allSql);
            $execution_logs->save();
            QueryLogger::clear();
        }

        return $response;
    }
}
