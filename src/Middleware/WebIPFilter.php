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
 * (the login pages: shown, and a user who is not exempt is refused at the login: recorded as a
 * failure, not as a success), "assets" (the images of the login page: shown while there are
 * exemptions), "strict" (no exemption: the install, the file shares).
 */
class WebIPFilter extends IpFilterBase
{
    public const SESSION_EXEMPT_IP = 'exment_ip_filter_exempt_ip';
    /** a request of the login pages from an address out of the filter */
    public const ATTRIBUTE_OUTSIDE = 'exment_ip_filter_outside';
    /** the login of the request refused (and recorded) */
    public const ATTRIBUTE_REFUSED = 'exment_ip_filter_refused';

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
        if ($this->mode === 'strict' || !IpFilterExemptions::configured()) {
            return $this->returnError();
        }
        // the images of the login page (the favicon, the background, the header)
        if ($this->mode === 'assets') {
            return $request->isMethod('GET') ? $next($request) : $this->returnError();
        }
        if (!$request->hasSession()) {
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

        // the login of a user who is not exempt is refused when it is made (LoginRecorder): no
        // success, no notice of a new address
        $request->attributes->set(static::ATTRIBUTE_OUTSIDE, true);
        $response = $next($request);
        // logged in now: only a user who is exempt stays
        $user = $guard->user();
        if (isset($user)) {
            return $this->exempt($request, $user) ? $response : $this->refuse($request, $user);
        }
        return $response;
    }

    /**
     * Whether a login now made is refused: from an address out of the filter, by a user who is not
     * exempt. Asked by LoginRecorder, before the login is recorded.
     *
     * @param mixed $user
     */
    public static function refusesLogin($user): bool
    {
        $request = request();
        if ($request->attributes->get(static::ATTRIBUTE_OUTSIDE) !== true || IpFilterExemptions::isExempt($user)) {
            return false;
        }
        $request->attributes->set(static::ATTRIBUTE_REFUSED, true);
        return true;
    }

    /**
     * Whether the login of the request was refused (its logout is not recorded).
     */
    public static function refused(): bool
    {
        return request()->attributes->get(static::ATTRIBUTE_REFUSED) === true;
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
        if (!static::refused()) {
            LoginRecorder::ipRefused($user);
            $request->attributes->set(static::ATTRIBUTE_REFUSED, true);
        }
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
