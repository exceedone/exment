<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Services\ApiUsers;
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
                // a good key on a route or table it is not for: forbidden; else: who are you
                if (UserApiKeys::isForbiddenReason(UserApiKeys::$lastRefusal)) {
                    return abortJson(403, ErrorCode::WRONG_SCOPE());
                }
                return abortJson(401, ErrorCode::ACCESS_DENIED());
            }
            \Auth::guard(Define::AUTHENTICATE_KEY_API)->setUser($login_user);
            \Exment::setGuard(Define::AUTHENTICATE_KEY_API);
            return static::allowedUser() ? $next($request) : static::refuseUser();
        }

        if (\Auth::guard(Define::AUTHENTICATE_KEY_API)->check()) {
            \Exment::setGuard(Define::AUTHENTICATE_KEY_API);
        } else {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        return static::allowedUser() ? $next($request) : static::refuseUser();
    }

    /**
     * Whether the user of the request may use the API (ApiUsers, ISS02915): an access token, an
     * API key, MCP and the API of the plugins alike.
     */
    public static function allowedUser(): bool
    {
        return ApiUsers::allows(\Exment::user());
    }

    /**
     * @return mixed
     */
    public static function refuseUser()
    {
        return abortJson(403, ErrorCode::API_NOT_ALLOWED());
    }
}
