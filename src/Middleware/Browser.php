<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;

/**
 * Middleware as Browser.
 * First call. check browser for exclude IE.
 */
class Browser
{
    public function handle(Request $request, \Closure $next)
    {
        $browser = strtolower($request->server('HTTP_USER_AGENT'));

        if (mb_strstr($browser, 'trident') || mb_strstr($browser, 'msie')) {
            return response(view('exment::exception.browser'));
        }

        return $next($request);
    }
}
