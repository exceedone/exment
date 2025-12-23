<?php

namespace Exceedone\Exment\Middleware;

use Closure;

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
            $end = microtime(true);
            $duration = $end - $start;
            \Log::info("実行時間: {$duration} s");
        }

        return $response;
    }
}
