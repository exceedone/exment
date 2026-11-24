<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Services\UserTimezone;
use Illuminate\Http\Request;

/**
 * The language and the time zone of the user (ISS02835). After the login is known.
 *
 * web (default): the language and the time zone of the user.
 * api: the language only (the user of the key, or Accept-Language); the date and times of the API stay
 * in the time zone of the site.
 */
class UserLocaleTimezone
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, string $type = 'web')
    {
        try {
            $user = \Exment::user();
        } catch (\Throwable $ex) {
            $user = null;
        }

        try {
            UserLocale::apply(UserLocale::resolve($user, $request));
            UserTimezone::setCurrent($type === 'api' || is_null($user) ? null : UserTimezone::forUser($user));
        } catch (\Throwable $ex) {
            // before the installation etc.: the language and the time zone of the site
            UserTimezone::setCurrent(null);
        }

        return $next($request);
    }

    /**
     * The request ended (several requests in one process: the tests, Octane).
     */
    public function terminate(Request $request, mixed $response): void
    {
        UserTimezone::setCurrent(null);
    }
}
