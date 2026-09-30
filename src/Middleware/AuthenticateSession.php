<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Auth\SessionPasswordHash;
use Exceedone\Exment\Model\Define;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign out the session that signed in before the password of the user was changed.
 * Set before the middleware that checks login, then the request is handled as not signed in.
 */
class AuthenticateSession
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        SessionPasswordHash::begin();

        if (!SessionPasswordHash::verify($request)) {
            $request->session()->put(Define::SYSTEM_KEY_SESSION_PASSWORD_CHANGED_LOGOUT, true);
        }

        $showMessage = $this->isShowLogoutMessage($request);
        if ($showMessage) {
            // Not $request->session(). now() is not defined on the contract it returns
            session()->now('status_error', exmtrans('login.signed_out_password_changed'));
        }

        $response = $next($request);

        // If login page was not shown, ex. redirected to SSO, show next time
        if ($showMessage && $response instanceof Response && $response->isSuccessful()) {
            $request->session()->forget(Define::SYSTEM_KEY_SESSION_PASSWORD_CHANGED_LOGOUT);
        }

        SessionPasswordHash::store($request);

        return $response;
    }

    /**
     * Whether to show why the browser was signed out. It is shown on login page.
     * Not flash data. Before login page is shown, the browser is redirected, and may send ajax or pjax requests.
     *
     * @param Request $request
     * @return bool
     */
    protected function isShowLogoutMessage(Request $request): bool
    {
        if (!$request->hasSession() || !$request->session()->has(Define::SYSTEM_KEY_SESSION_PASSWORD_CHANGED_LOGOUT)) {
            return false;
        }

        return $request->isMethod('get') && !$request->ajax() && !$request->pjax() && isMatchRequest('auth/login');
    }
}
