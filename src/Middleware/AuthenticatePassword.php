<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\PasswordHistory;
use Carbon\Carbon;

class AuthenticatePassword extends \Encore\Admin\Middleware\Authenticate
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
        // not use password policy and expiration days, go next
        if (!boolval(config('exment.password_policy_enabled', false)) ||
            empty($expiration_days = System::password_expiration_days())) {
            return $next($request);
        }

        $user = \Exment::user();

        // get password latest history
        $last_history = PasswordHistory::where('base_user_id', $user->base_user_id)
            ->orderby('created_at', 'desc')->first();

        // calc diff days
        $diff_days = $last_history->created_at->diffInDays(Carbon::now());

        if ($diff_days > $expiration_days) {
            return redirect(admin_url('auth/change'));
        }

        return $next($request);
    }
}
