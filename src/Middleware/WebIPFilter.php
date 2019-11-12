<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Middleware as web ip address filter.
 * First call. check ip address is permitted.
 */
class WebIPFilter
{
    public function handle(Request $request, \Closure $next)
    {
        if (config('exment.ip_filter_disabled', false)) {
            return $next($request);
        }

        $web_ip_filters = System::web_ip_filters();

        if (!empty($web_ip_filters)) {
            $web_ip_filters = explode("\r\n", $web_ip_filters);
            if (!IpUtils::checkIp($request->ip(), $web_ip_filters)) {
                return response(view('exment::exception.ipfilter'));
            }
        }

        return $next($request);
    }
}
