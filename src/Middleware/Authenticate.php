<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Encore\Admin\Facades\Admin;

class Authenticate extends \Encore\Admin\Middleware\Authenticate
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
        $shouldPassThrough = shouldPassThrough();
        if($shouldPassThrough){
            return $next($request);
        }

        $user = \Admin::user();
        if(is_null($user) || is_null($user->base_user)){
            return redirect()->guest(admin_base_path('auth/login'));
        }

        return $next($request);
    }
}
