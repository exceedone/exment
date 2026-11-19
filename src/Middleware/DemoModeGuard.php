<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\DemoMode;
use Illuminate\Http\Request;

/**
 * Refuses the operations that demo mode does not allow (config "exment.demo_mode").
 * The list of operations is in Exceedone\Exment\Services\DemoMode.
 */
class DemoModeGuard
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if (DemoMode::isBlockedRequest($request)) {
            return DemoMode::refuse($request);
        }

        return $next($request);
    }
}
