<?php

namespace Exceedone\Exment\Middleware;

use Fideloper\Proxy\TrustProxies as BaseTrustProxies;
use Closure;
use Illuminate\Http\Request;

class TrustProxies extends BaseTrustProxies
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // get config
        $ips = stringToArray(config('exment.trust_proxy_ips', []));
        $headers = config('exment.trust_proxy_headers');
        if(is_nullorempty($ips) && is_nullorempty($headers)){
            return $next($request);
        }

        if(!is_nullorempty($ips)){
            $this->proxies = $ips;
        }
        if(!is_nullorempty($headers)){
            $this->headers = constant("\Illuminate\Http\Request::$headers");
        }

        $this->setTrustedProxyIpAddresses($request);
        return $next($request);
    }

    /**
     * Sets the trusted proxies on the request to the value of trustedproxy.proxies
     *
     * @param \Illuminate\Http\Request $request
     */
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $trustedIps = $this->proxies ?: $this->config->get('trustedproxy.proxies');

        // Trust any IP address that calls us
        // `**` for backwards compatibility, but is deprecated
        if ($trustedIps === '*' || $trustedIps === '**') {
            return $this->setTrustedProxyIpAddressesToTheCallingIp($request);
        }

        // Support IPs addresses separated by comma
        $trustedIps = is_string($trustedIps) ? array_map('trim', explode(',', $trustedIps)) : $trustedIps;

        // Only trust specific IP addresses
        if (is_array($trustedIps)) {
            return $this->setTrustedProxyIpAddressesToSpecificIps($request, $trustedIps);
        }
    }

    /**
     * Specify the IP addresses to trust explicitly.
     *
     * @param \Illuminate\Http\Request $request
     * @param array                    $trustedIps
     */
    private function setTrustedProxyIpAddressesToSpecificIps(Request $request, $trustedIps)
    {
        $request->setTrustedProxies((array) $trustedIps, $this->getTrustedHeaderNames());
    }

    /**
     * Set the trusted proxy to be the IP address calling this servers
     *
     * @param \Illuminate\Http\Request $request
     */
    private function setTrustedProxyIpAddressesToTheCallingIp(Request $request)
    {
        $request->setTrustedProxies([$request->server->get('REMOTE_ADDR')], $this->getTrustedHeaderNames());
    }

    /**
     * Retrieve trusted header name(s), falling back to defaults if config not set.
     *
     * @return int A bit field of Request::HEADER_*, to set which headers to trust from your proxies.
     */
    protected function getTrustedHeaderNames()
    {
        $headers = $this->headers ?: $this->config->get('trustedproxy.headers');
        switch ($headers) {
            case 'HEADER_X_FORWARDED_AWS_ELB':
            case Request::HEADER_X_FORWARDED_AWS_ELB:
                return Request::HEADER_X_FORWARDED_AWS_ELB;
                break;
            case 'HEADER_FORWARDED':
            case Request::HEADER_FORWARDED:
                return Request::HEADER_FORWARDED;
                break;
            case 'HEADER_X_FORWARDED_ALL':
            case Request::HEADER_X_FORWARDED_ALL:
                return Request::HEADER_X_FORWARDED_ALL;
                break;
        }

        return $headers;
    }
}
