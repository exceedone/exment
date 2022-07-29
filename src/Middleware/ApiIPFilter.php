<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Enums\ErrorCode;

/**
 * Middleware as web ip address filter.
 * First call. check ip address is permitted.
 */
class ApiIPFilter extends IpFilterBase
{
    public function handle(Request $request, \Closure $next)
    {
        return $this->handleBase($request, $next, 'api_ip_filters');
    }

    protected function returnError()
    {
        return abortJson(400, ErrorCode::DISAPPROVAL_IP());
    }
}
