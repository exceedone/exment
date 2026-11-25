<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\IpAddress;

/**
 * Middleware as ip address filter.
 * First call. check ip address is permitted.
 */
abstract class IpFilterBase
{
    /**
     * @param Request $request
     * @param \Closure $next
     * @param string $filterFuncName
     * @return mixed
     */
    public function handleBase(Request $request, \Closure $next, $filterFuncName)
    {
        if (config('exment.ip_filter_disabled', false)) {
            return $next($request);
        }
        if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
            return $next($request);
        }

        $filters = System::{$filterFuncName}();
        if (is_nullorempty($filters)) {
            return $next($request);
        }

        // one per line (or comma separated), IPv4 and IPv6, an address or a range (ISS02909)
        $filters = IpAddress::splitRules(strval($filters));
        if (count($filters) === 0) {
            return $next($request);
        }

        if (!IpAddress::matches($request->ip(), $filters)) {
            return $this->returnError();
        }

        return $next($request);
    }

    /**
     * @return mixed
     */
    abstract protected function returnError();
}
