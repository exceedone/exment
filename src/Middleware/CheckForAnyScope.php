<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Enums\ErrorCode;

class CheckForAnyScope
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  mixed  ...$scopes
     * @return \Illuminate\Http\Response
     * @throws \Illuminate\Auth\AuthenticationException|\Laravel\Passport\Exceptions\MissingScopeException
     */
    public function handle($request, $next, ...$scopes)
    {
        $user = \Exment::user();
        if (is_null($user) || is_null($user->base_user)) {
            return abortJson(401, ErrorCode::ACCESS_DENYED());
        }

        foreach ($scopes as $scope) {
            if ($user->tokenCan($scope)) {
                return $next($request);
            }
        }

        return abortJson(403, ErrorCode::WRONG_SCOPE());
    }
}
