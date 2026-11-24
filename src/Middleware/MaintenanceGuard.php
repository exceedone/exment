<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\MaintenanceMode;
use Illuminate\Http\Request;

/**
 * Maintenance mode (Services\MaintenanceMode): who does not get in gets the
 * maintenance page (HTTP 503). The parameter is the kind of the pages:
 * web, webapi, anonymous (the login page), api, api_anonymous (getting a
 * token), public (public forms and share links).
 */
class MaintenanceGuard
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, string $kind = MaintenanceMode::KIND_WEB)
    {
        $state = MaintenanceMode::current();
        if (!isset($state)) {
            return $next($request);
        }
        $user = in_array($kind, [MaintenanceMode::KIND_PUBLIC, MaintenanceMode::KIND_ANONYMOUS, MaintenanceMode::KIND_API_ANONYMOUS], true) ? null : \Exceedone\Exment\Facades\ExmentFacade::user();
        if (MaintenanceMode::passes($state, $request, $kind, $user)) {
            return $next($request);
        }
        if ($kind === MaintenanceMode::KIND_WEB && isset($user)) {
            // a user who may not come in now is logged out: the login does not stay for after
            try {
                \Illuminate\Support\Facades\Auth::guard("admin")->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                }
            } catch (\Throwable $e) {
            }
        }
        return MaintenanceMode::response($state, $request, $kind);
    }
}
