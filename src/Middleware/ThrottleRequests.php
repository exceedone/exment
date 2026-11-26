<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\IpAddress;
use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;

/**
 * "throttle:10,1" of a route, as Laravel's, except that a request without a user counts an IPv6
 * address as its /64 (ISS02909): one IPv6 client can take a new address in its network for
 * every try. The named limiters (throttle:api) build their own keys (RouteServiceProvider).
 */
class ThrottleRequests extends BaseThrottleRequests
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        if ($request->user() || !($route instanceof \Illuminate\Routing\Route)) {
            return parent::resolveRequestSignature($request);
        }
        $value = $route->getDomain() . '|' . IpAddress::limiterKey($request->ip());
        return static::$shouldHashKeys ? sha1($value) : $value;
    }
}
