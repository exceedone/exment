<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;

class Authenticate2factor extends \ExmentAdminCore\Admin\Middleware\Authenticate
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
        if (!Auth2factorService::isUse2factor()) {
            return $next($request);
        }

        // check url
        $excepts = [
            admin_base_path('auth-2factor'),
            admin_base_path('auth-2factor/verify'),
            admin_base_path('auth-2factor/logout'),
            admin_base_path('auth-2factor/google/sendmail'),
            admin_base_path('auth-2factor/google/register'),
            admin_base_path('auth-2factor/recovery'),
            admin_base_path('auth-2factor/recovery/verify'),
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
        if (boolval($auth2factor)) {
            return $next($request);
        }

        $login_user = \Exment::user();
        if (!($login_user instanceof LoginUser)) {
            return redirect(admin_url('auth-2factor'));
        }

        // specified by role group: the user is not required 2factor, go next
        if (!Auth2factorService::isRequiredFor($login_user)) {
            Auth2factorService::clearRequiredAt($login_user);
            return $next($request);
        }

        // required but not set up yet: the user can use Exment for a while (grace period), with a warning on every page.
        // After the period, every page is redirected to the 2factor page to set it up.
        if (Auth2factorService::isSetupPending($login_user)) {
            Auth2factorService::startSetupGrace($login_user);

            $remaining = Auth2factorService::getSetupGraceRemainingMinutes($login_user);
            if (!is_null($remaining)) {
                admin_warning_once(
                    exmtrans('2factor.message.setup_required_title'),
                    exmtrans('2factor.message.setup_required_grace', $remaining, esc_html(admin_url('auth-2factor')))
                );
                return $next($request);
            }
        }

        return redirect(admin_url('auth-2factor'));
    }
}
