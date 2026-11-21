<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\UserApiKeys;

class AuthenticateApi extends \ExmentAdminCore\Admin\Middleware\Authenticate
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
        // an API key of a user (Services\UserApiKeys) in place of an OAuth access token
        $bearer = $request->bearerToken();
        if (UserApiKeys::looksLikeKey($bearer)) {
            $login_user = UserApiKeys::authenticate($request, (string)$bearer);
            if (!isset($login_user)) {
                return abortJson(401, ErrorCode::ACCESS_DENIED());
            }
            \Auth::guard(Define::AUTHENTICATE_KEY_API)->setUser($login_user);
            \Exment::setGuard(Define::AUTHENTICATE_KEY_API);
            return $next($request);
        }

        if (\Auth::guard(Define::AUTHENTICATE_KEY_API)->check()) {
            \Exment::setGuard(Define::AUTHENTICATE_KEY_API);
        } else {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        return $next($request);
    }
}
