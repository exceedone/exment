<?php

namespace Exceedone\Exment\Middleware;

use Closure;

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
        $user = \Exment::user();
        if(is_null($user) || is_null($user->base_user)){
            abort(401);
        }

        return $next($request);
    }
}
