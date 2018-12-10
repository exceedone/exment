<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

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
        if (Auth::guard('admin_api')->guest() && !$this->shouldPassThrough($request)) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * Determine if the request has a URI that should pass through verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function shouldPassThrough($request)
    {
        return false;
    }
}
