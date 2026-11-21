<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\MaintenanceMode;
use Illuminate\Http\Request;

/**
 * The operator's maintenance (Services\MaintenanceMode), checked first, for
 * every request: only the files of the operator, no database and no login,
 * so a version switch in progress answers the maintenance page (503) and
 * not an error. The allowed IP addresses go on; with "allow_admins" the
 * guards of the pages (MaintenanceGuard) decide after the login.
 */
class MaintenanceOperatorGuard
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        try {
            $response = MaintenanceMode::earlyResponse($request);
        } catch (\Throwable $e) {
            $response = null;
        }
        return $response ?? $next($request);
    }
}
