<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\Login\LoginHistoryService;
use Illuminate\Http\Response;

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
            return $this->nextWithAdministratorWarning($request, $next, 'admin_not_set_disabled', admin_url('login_setting'));
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
            return $this->nextWithAdministratorWarning($request, $next, 'admin_not_set_role_group', admin_url('role_group'));
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

    /**
     * Go next. A system administrator who logged in without 2factor is warned to set it up, once after each login:
     * the warning is done when a page has shown it, and the session is cleared at logout.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @param string $message key of "2factor.message"
     * @param string $url the page to set it up
     * @return mixed
     */
    protected function nextWithAdministratorWarning($request, Closure $next, string $message, string $url)
    {
        if (!$this->isAdministratorToWarn($request)) {
            return $next($request);
        }

        admin_warning_once(
            exmtrans('2factor.message.admin_not_set_title'),
            exmtrans("2factor.message.{$message}", esc_html($url))
        );
        $response = $next($request);

        // shown only on a page. Not on a redirect, a file, json...
        if ($response instanceof Response && $response->isSuccessful()
            && in_array(trim(explode(';', (string)$response->headers->get('Content-Type'))[0]), ['', 'text/html'], true)) {
            session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR_ADMIN_WARNED => true]);
        }
        return $response;
    }

    /**
     * Whether to warn the logged-in user: a system administrator not warned yet since the login.
     * Only on the sites where 2factor can be used (config "login_use_2factor"), so the sites without it see no change.
     * Not for OAuth / SAML, whose login is not subject to 2factor of Exment.
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    protected function isAdministratorToWarn($request): bool
    {
        if (!boolval(config('exment.login_use_2factor', false))) {
            return false;
        }
        // a page of the browser (pjax shows the warning too). An ajax response does not show it.
        if (!$request->isMethod('GET') || ($request->ajax() && !$request->pjax())) {
            return false;
        }
        if (boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR_ADMIN_WARNED))) {
            return false;
        }

        $login_user = \Exment::user();
        return $login_user instanceof LoginUser
            && $login_user->isAdministrator()
            && !in_array($login_user->login_type, LoginHistoryService::LOGIN_TYPES_WITHOUT_2FACTOR);
    }
}
