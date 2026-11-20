<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

class AuthenticateWebApi extends \Encore\Admin\Middleware\Authenticate
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
        if (\Auth::guard(Define::AUTHENTICATE_KEY_WEB)->check()) {
            \Exment::setGuard(Define::AUTHENTICATE_KEY_WEB);
        } else {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        // The web guard is logged in right after the password step. The web
        // pages then send the user to the second factor and to the password
        // change (Authenticate2factor, AuthenticatePasswordLimit); this API is
        // called with the same session, so it has to wait for the same steps.
        if (static::isWaiting2factor() || static::isWaitingPasswordChange($request)) {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        return $next($request);
    }

    /**
     * Whether the user has passed the password but not yet the second factor.
     */
    protected static function isWaiting2factor(): bool
    {
        if (!boolval(config('exment.login_use_2factor', false)) || !boolval(System::login_use_2factor())) {
            return false;
        }
        return !boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR));
    }

    /**
     * Whether the user has to change the password before going on.
     */
    protected static function isWaitingPasswordChange(\Illuminate\Http\Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }
        return $request->session()->has(Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT)
            || $request->session()->has(Define::SYSTEM_KEY_SESSION_FIRST_CHANGE_PASSWORD);
    }
}
