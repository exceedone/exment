<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Marks every answer of Exment with the header X-Exment (ISS02935), so the screen can tell its own
 * answers from one that something in front of the site gave instead: a firewall (AWS WAF,
 * Cloudflare, a proxy of the company) that refused what was sent - a body with an URL, an IP
 * address or a path in it - and answered with a 403 page of its own. Exment then says so
 * (blocked_notice.js) instead of "no permission".
 *
 * The header says nothing but that the answer is Exment's.
 */
class MarkResponse
{
    public const HEADER = 'X-Exment';

    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (is_object($response) && isset($response->headers) && !$response->headers->has(static::HEADER)) {
            $response->headers->set(static::HEADER, '1');
        }

        return $response;
    }
}
