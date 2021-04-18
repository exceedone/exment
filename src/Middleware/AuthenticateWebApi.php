<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\Define;

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

        return $next($request);
    }
}
