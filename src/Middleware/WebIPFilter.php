<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Middleware as web ip address filter.
 * First call. check ip address is permitted.
 */
class WebIPFilter extends IpFilterBase
{
    public function handle(Request $request, \Closure $next)
    {
        return $this->handleBase($request, $next, 'web_ip_filters');
    }
    
    protected function returnError(){
        return response(view('exment::exception.ipfilter'));
    }
}
