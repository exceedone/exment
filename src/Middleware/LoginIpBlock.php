<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Services\Login\LoginIpBlockService;

/**
 * IP block: refuses posting the login form from an IP address blocked by too many failed logins, before the authentication.
 * (So a blocked IP address cannot know whether the password is correct)
 */
class LoginIpBlock
{
    /**
     * @param Request $request
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        return app(LoginIpBlockService::class)->handleLoginRequest($request) ?? $next($request);
    }
}
