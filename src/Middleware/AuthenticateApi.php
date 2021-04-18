<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\Define;

class AuthenticateApi extends \Encore\Admin\Middleware\Authenticate
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
        if (\Auth::guard(Define::AUTHENTICATE_KEY_API)->check()) {
            \Exment::setGuard(Define::AUTHENTICATE_KEY_API);
        } else {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        return $next($request);
    }
}
