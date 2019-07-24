<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;

class Authenticate2factor extends \Encore\Admin\Middleware\Authenticate
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
        // not use 2 factor, go next
        if (!boolval(config('exment.login_use_2factor', false)) || !boolval(System::login_use_2factor())) {
            return $next($request);
        }

        // check url
        $excepts = [
            admin_base_path('auth-2factor'),
            admin_base_path('auth-2factor/verify'),
            admin_base_path('auth-2factor/logout'),
            admin_base_path('auth-2factor/google/sendmail'),
            admin_base_path('auth-2factor/google/register'),
        ];
        foreach ($excepts as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except)) {
                return $next($request);
            }
        }

        // get session
        $auth2factor = session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR);
        if (!boolval($auth2factor)) {
            return redirect(admin_url('auth-2factor'));
        }

        return $next($request);
    }
}
