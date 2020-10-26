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
        $ips = config('exment.trust_proxy_ips', []);
        $headers = config('exment.trust_proxy_headers');
        if(is_nullorempty($ips) && is_nullorempty($headers)){
            return $next($request);
        }

        if(!is_nullorempty($ips)){
            if ($ips === '*' || $ips === '**') {
                $this->proxies = $ips;
            }
            else{
                $this->proxies = stringToArray($ips);
            }
        }

        if(!is_nullorempty($headers)){
            $this->headers = constant("\Illuminate\Http\Request::$headers");
        }else{
            $this->headers = \Illuminate\Http\Request::HEADER_X_FORWARDED_ALL;
        }

        $this->setTrustedProxyIpAddresses($request);
        return $next($request);
    }
}
