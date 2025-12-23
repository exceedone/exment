<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Model\CustomTable;
use Carbon\Carbon;
use Exceedone\Exment\Model\System;



use Closure;
use Exceedone\Exment\Services\QueryLogger;

class CheckLoggingEnabled
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
        $response = $next($request);
        if (System::logging_toggle_available()) {
            $table = CustomTable::getEloquent('system_logs');

            if ($table) {
            $user = \Exment::user()->base_user;
            $email = $user->getValue('email');
            $url = $request->fullUrl();
            $date_time = Carbon::now()->toDateTimeString();
            $system_logs = $table->getValueModel();
            $system_logs->parent_id = null;
            $system_logs->parent_type = null;
            $system_logs->setValue('email', $email);
            $system_logs->setValue('create_at', $date_time);
            $system_logs->setValue('url', $url); 
            $allSql = implode("\n", QueryLogger::all());
            $system_logs->setValue('sql', $allSql);
            $system_logs->save();
            QueryLogger::clear();
            }
        }

        return $response;
    }
}
