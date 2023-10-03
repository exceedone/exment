<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Enums\ErrorCode;

class CheckForAnyScope
{
    /**
     * Handle the incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @param mixed ...$scopes
     * @return mixed|\Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, $next, ...$scopes)
    {
        $user = \Exment::user();
        if (is_null($user) || is_null($user->base_user)) {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        foreach ($scopes as $scope) {
            if ($user->tokenCan($scope)) {
                return $next($request);
            }
        }

        return abortJson(403, ErrorCode::WRONG_SCOPE());
    }
}
