<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\LoginSetting;

/**
 * Middleware as Login.
 */
class Login
{
    public function handle(Request $request, \Closure $next)
    {
        // check whether SSO url redirect
        if ($request->is(trim(admin_base_path('auth/login'), '/'))
            && !is_null($exment_login_url = LoginSetting::getRedirectSSOForceUrl())
            && $request->session()->get('errors')) {
            return redirect($exment_login_url);
        }

        return $next($request);
    }
}
