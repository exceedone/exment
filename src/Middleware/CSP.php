<?php

namespace Exceedone\Exment\Middleware;

use Closure;

class CSP
{
    // Directives that have NO fallback to default-src and must always be explicit.
    private const NO_FALLBACK_DIRECTIVES = "base-uri 'self'; form-action 'self'; frame-ancestors 'self';";

    public function handle($request, Closure $next)
    {
        $nonce = base64_encode(random_bytes(16));
        app()->instance('csp-nonce', $nonce);
        view()->share('cspNonce', $nonce);

        $response = $next($request);

        $csp = app()->isProduction()
            ? "default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; " . self::NO_FALLBACK_DIRECTIVES
            : "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; " . self::NO_FALLBACK_DIRECTIVES;

        return $response->header('Content-Security-Policy', $csp);
    }
}
