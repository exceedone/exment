<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Middleware as web ip address filter.
 * First call. check ip address is permitted.
 */
class ApiIPFilter
{
    public function handle(Request $request, \Closure $next)
    {
        if (config('exment.ip_filter_disabled', false)) {
            return $next($request);
        }

        $api_ip_filters = System::api_ip_filters();

        if (!empty($api_ip_filters)) {
            $api_ip_filters = explode("\r\n", $api_ip_filters);
            if (!IpUtils::checkIp($request->ip(), $api_ip_filters)) {
	            return abortJson(400, exmtrans('api.errors.api_ip_filtered'));
            }
        }

        return $next($request);
    }
}
