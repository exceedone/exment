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
        if (isMatchRequest('auth/login')
            && !is_null($exment_login_url = LoginSetting::getRedirectSSOForceUrl())) {
            $errors = $request->session()->get('errors');
            if (\is_nullorempty($errors)) {
                return redirect($exment_login_url);
            }
        }

        return $next($request);
    }
}
