<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Services\ApiCost;
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

    /**
     * The "api" limiter counts the points of the call when exment.api_rate_cost is on or log
     * (ISS02885 #5, ApiCost).
     *
     * @param \Illuminate\Http\Request $request
     * @param string $limiterName
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleRequestUsingNamedLimiter($request, Closure $next, $limiterName, Closure $limiter)
    {
        if ($limiterName === 'api' && ApiCost::mode() !== ApiCost::MODE_OFF) {
            $request->attributes->set(static::COST_ATTRIBUTE, ApiCost::ofRequest($request));
        }
        return parent::handleRequestUsingNamedLimiter($request, $next, $limiterName, $limiter);
    }

    /**
     * The request attribute with the points of the call.
     */
    public const COST_ATTRIBUTE = 'exment.api_cost';

    /**
     * @param \Illuminate\Http\Request $request
     * @param array<int, object{key: string, maxAttempts: int, decaySeconds: int, afterCallback: callable|null, responseCallback: callable|null}> $limits
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $points = $request->attributes->get(static::COST_ATTRIBUTE);
        if (!is_int($points)) {
            return parent::handleRequest($request, $next, $limits);
        }

        if (ApiCost::mode() === ApiCost::MODE_LOG) {
            // limited as before; the points are only counted beside, and logged when over
            foreach ($limits as $limit) {
                ApiCost::logOverLimit($limit->key, $points, intval($limit->maxAttempts), intval($limit->decaySeconds), function () use ($request) {
                    return 'An API client ' . ApiCost::whoOf($request);
                });
            }
            return parent::handleRequest($request, $next, $limits);
        }

        // the points of this call must fit in what is left of the minute
        foreach ($limits as $limit) {
            if ($this->limiter->attempts($limit->key) + $points > $limit->maxAttempts) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }
        }
        foreach ($limits as $limit) {
            $this->limiter->increment($limit->key, $limit->decaySeconds, $points);
        }

        $response = $next($request);

        // X-RateLimit-Limit / X-RateLimit-Remaining in points
        foreach ($limits as $limit) {
            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }
}
