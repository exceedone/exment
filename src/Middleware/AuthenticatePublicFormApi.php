<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\Define;

class AuthenticatePublicFormApi extends \Encore\Admin\Middleware\Authenticate
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
        if (\Auth::guard(Define::AUTHENTICATE_KEY_PUBLIC_FORM)->check()) {
            \Exment::setGuard(Define::AUTHENTICATE_KEY_PUBLIC_FORM);
        } else {
            return abortJson(401, ErrorCode::ACCESS_DENIED());
        }

        return $next($request);
    }
}
