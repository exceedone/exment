<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\Permission;

class AuthenticatePluginApi extends \Encore\Admin\Middleware\Authenticate
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // get plugin id
        $plugin = $this->getPlugin($request);
        if (!isset($plugin)) {
            return abortJson(500, ErrorCode::PLUGIN_NOT_FOUND());
        }

        $user = \Exment::user();
        if (is_null($user) || is_null($user->base_user)) {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        if (!$user->hasPermissionPlugin($plugin, Permission::PLUGIN_ACCESS)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }


        return $next($request);
    }

    /**
     * Get Plugin Model from name
     *
     * @param [type] $request
     * @return void
     */
    protected function getPlugin($request)
    {
        $name = $request->route()->getName();
        if (!isset($name)) {
            return null;
        }

        $names = explode(".", $name);
        if (count($names) < 3) {
            return null;
        }

        return Plugin::getEloquent($names[2]);
    }
}
