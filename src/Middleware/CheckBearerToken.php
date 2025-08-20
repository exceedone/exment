<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to validate Bearer Token and client IP address.
 *
 * This middleware checks:
 *  - If the Authorization Bearer Token from the request matches the API_TOKEN defined in .env
 *  - If the client's IP address is in the allowed list (API_ALLOWED_IPS in .env)
 *
 * If the related environment variables are not defined, the checks are skipped.
 * If a check fails, the request will be rejected with a 401 Unauthorized response.
 */
class CheckBearerToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // === Check Bearer Token if configured ===
        $expectedToken = env('EXMENT_TENANT_API_TOKEN');
        if (!empty($expectedToken)) {
            $token = $request->bearerToken();
            if ($token !== $expectedToken) {
                return response()->json(['error' => 'Unauthorized: Invalid token'], 401);
            }
        }

        // === Check IP Address if configured ===
        $allowedIpsEnv = env('EXMENT_TENANT_API_ALLOWED_IPS', '');
        if (!empty($allowedIpsEnv)) {
            $allowedIps = array_map('trim', explode(',', $allowedIpsEnv));
            $clientIp = $request->ip();

            if (!in_array($clientIp, $allowedIps, true)) {
                return response()->json(['error' => 'Unauthorized: IP not allowed'], 401);
            }
        }

        return $next($request);
    }
}