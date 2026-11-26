<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\IpFilterExemptions;
use Exceedone\Exment\Services\LogManagement\LoginRecorder;
use Illuminate\Http\Request;

/**
 * Middleware as web ip address filter.
 * First call. check ip address is permitted.
 *
 * The users chosen as exempt (IpFilterExemptions, ISS02915) may use the pages from an address out
 * of the filter. Modes (the parameter): "auth" (the pages of a login user: the default), "login"
 * (the login pages: shown, and a user who is not exempt is logged out after the login), "strict"
 * (no exemption: the pages before the login that are not the login, the install).
 */
class WebIPFilter extends IpFilterBase
{
    public const SESSION_EXEMPT_IP = 'exment_ip_filter_exempt_ip';

    /** @var string */
    protected $mode = 'auth';

    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, string $mode = 'auth')
    {
        $this->mode = $mode;
        return $this->handleBase($request, $next, 'web_ip_filters');
    }

    /**
     * @return mixed
     */
    protected function outside(Request $request, \Closure $next)
    {
        if ($this->mode === 'strict' || !$request->hasSession() || !IpFilterExemptions::configured()) {
            return $this->returnError();
        }
        $guard = \Auth::guard(Define::AUTHENTICATE_KEY_WEB);
        $user = $guard->user();
        if (isset($user)) {
            return $this->exempt($request, $user) ? $next($request) : $this->refuse($request, $user);
        }
        if ($this->mode !== 'login') {
            // the login page is the only page before the login
            return $request->ajax() || $request->expectsJson() || !$request->isMethod('GET')
                ? $this->returnError()
                : redirect()->guest(admin_base_path('auth/login'));
        }

        $response = $next($request);
        // logged in now: only a user who is exempt stays
        $user = $guard->user();
        if (isset($user)) {
            return $this->exempt($request, $user) ? $response : $this->refuse($request, $user);
        }
        return $response;
    }

    /**
     * Whether the user is exempt; recorded once a session and address.
     *
     * @param mixed $user
     */
    protected function exempt(Request $request, $user): bool
    {
        if (!IpFilterExemptions::isExempt($user)) {
            return false;
        }
        $session = $request->session();
        if ($session->get(static::SESSION_EXEMPT_IP) !== $request->ip()) {
            $session->put(static::SESSION_EXEMPT_IP, $request->ip());
            LoginRecorder::ipExempt($user);
        }
        return true;
    }

    /**
     * @param mixed $user
     * @return mixed
     */
    protected function refuse(Request $request, $user)
    {
        LoginRecorder::ipRefused($user);
        \Auth::guard(Define::AUTHENTICATE_KEY_WEB)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return $this->returnError();
    }

    /**
     * @return mixed
     */
    protected function returnError()
    {
        return response(view('exment::exception.ipfilter'));
    }
}
