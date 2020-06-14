<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;

class AuthenticatePasswordLimit extends \Encore\Admin\Middleware\Authenticate
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
        // not have Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT and SYSTEM_KEY_SESSION_FIRST_CHANGE_PASSWORD, go next
        if (!$request->session()->has(Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT) &&
            !$request->session()->has(Define::SYSTEM_KEY_SESSION_FIRST_CHANGE_PASSWORD)) {
            return $next($request);
        }

        // check url
        $excepts = [
            admin_base_path('auth/change'),
        ];
        foreach ($excepts as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except)) {
                return $next($request);
            }
        }

        return redirect(admin_url('auth/change'));
    }
}
