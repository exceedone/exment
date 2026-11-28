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
 *
 * A request of a script (ajax, JSON; not pjax) does not use up a message kept for the next page:
 * a page sends such requests by itself (notices, lists), and one that came between the redirect and
 * the next page took the message, so that page showed nothing. The message kept for the next page
 * is kept once more, as it was; a message the script request writes itself is not.
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
        $waiting = $this->isScriptRequest($request) ? $this->waitingMessages($request) : [];

        $response = $next($request);

        if (count($waiting) > 0) {
            $this->keepWaitingMessages($request, $waiting);
        }

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

    /**
     * A request of a script: ajax or JSON, not pjax (a pjax request draws the page, with the message).
     */
    protected function isScriptRequest(Request $request): bool
    {
        return !$request->pjax() && ($request->ajax() || $request->expectsJson());
    }

    /**
     * The messages kept for this request by the one before (flash data to be removed after it).
     *
     * @return array<string, mixed> key => message
     */
    protected function waitingMessages(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }
        $session = $request->session();
        $old = (array)$session->get('_flash.old', []);

        $waiting = [];
        foreach (static::MESSAGE_KEYS as $key) {
            if (in_array($key, $old, true) && $session->has($key)) {
                $waiting[$key] = $session->get($key);
            }
        }
        return $waiting;
    }

    /**
     * Keep the messages that were kept for this request for the next one, when the request did not
     * change or remove them.
     *
     * @param array<string, mixed> $waiting key => message
     */
    protected function keepWaitingMessages(Request $request, array $waiting): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->session();
        $keys = [];
        foreach ($waiting as $key => $message) {
            if ($session->has($key) && $session->get($key) === $message) {
                $keys[] = $key;
            }
        }
        if (count($keys) > 0) {
            $session->keep($keys);
        }
    }
}
