<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Keep the message of admin_info() / admin_success() / admin_warning() / admin_error() for the
 * next request when the answer is a redirect, so the page the browser goes to shows it.
 *
 * exment-admin-core stores these messages with session()->now(): they live for the current
 * request only. That is right for a page drawn in the same request (a flashed message would show
 * there and once more on the next page), but a message set before a redirect - an expired public
 * form, a mail that could not be sent - was lost, while v6's laravel-admin flashed it and the next
 * page showed it. This middleware sits right after StartSession in the "adminweb" group, so it also
 * sees the redirects the exception handler makes (an expired CSRF token).
 */
class KeepAdminMessageOnRedirect
{
    /**
     * The session keys admin_info() and its siblings write, which admin::partials.alerts reads.
     */
    protected const MESSAGE_KEYS = ['info', 'success', 'warning', 'error'];

    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (!$response instanceof RedirectResponse || !$request->hasSession()) {
            return $response;
        }

        $session = $request->session();
        $keys = array_values(array_filter(static::MESSAGE_KEYS, function ($key) use ($session) {
            return $session->has($key);
        }));
        if (count($keys) > 0) {
            // flash data for one more request: saving the session removes now() data, not this
            $session->keep($keys);
        }

        return $response;
    }
}
