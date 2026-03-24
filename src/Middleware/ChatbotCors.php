<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * CORS middleware for chatbot API endpoints.
 * Validates the incoming Origin against the configured allowed origins
 * (exment.chatbot_cors_origin) and adds the appropriate response headers.
 * Responds to OPTIONS preflight requests without forwarding to the application.
 */
class ChatbotCors
{
    public function handle(Request $request, Closure $next)
    {
        // Respond immediately to OPTIONS preflight without running further middleware
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            $this->addCorsHeaders($request, $response);
            return $response;
        }

        $response = $next($request);
        $this->addCorsHeaders($request, $response);

        return $response;
    }

    private function addCorsHeaders(Request $request, $response): void
    {
        $requestOrigin = $request->header('Origin');

        $allowedOrigins = $this->getAllowedOrigins();

        // Remove any wildcard/existing value set by a global CORS middleware
        // so our explicit value always takes precedence.
        $response->headers->remove('Access-Control-Allow-Origin');
        if (empty($allowedOrigins)) {
            // No configured origins: lock to the current server's origin so
            // a wildcard "*" from an upstream middleware can never leak through.
            $origin = $requestOrigin ?: $request->getSchemeAndHttpHost();
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } else {
            if (!$requestOrigin || !in_array($requestOrigin, $allowedOrigins, true)) {
                return;
            }
            $response->headers->set('Access-Control-Allow-Origin', $requestOrigin);
        }

        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token, X-Requested-With');
        $response->headers->set('Vary', 'Origin');
    }

    private function getAllowedOrigins(): array
    {
        $origins = config('exment.chatbot_cors_origin', '');
        if (!$origins) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $origins))));
    }
}
