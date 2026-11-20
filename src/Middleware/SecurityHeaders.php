<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Headers for the admin pages (and the login pages):
 * - X-Frame-Options: another site may not show them in a frame (clickjacking).
 *   EXMENT_X_FRAME_OPTIONS changes it ("DENY"), or turns it off ("" for none).
 * - X-Content-Type-Options: nosniff, so a file is not read as another type.
 * Public forms are not under this middleware, so they can still be embedded.
 * A header a response sets itself is kept.
 */
class SecurityHeaders
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (!is_object($response) || !isset($response->headers)) {
            return $response;
        }

        $frame = config('exment.x_frame_options', 'SAMEORIGIN');
        if (!is_nullorempty($frame) && !$response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', $frame);
        }
        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
        return $response;
    }
}
