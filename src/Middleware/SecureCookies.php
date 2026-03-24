<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\Cookie;

class SecureCookies
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $isSecure = $request->isSecure();

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie(
                $cookie->getName(),
                $cookie->getPath(),
                $cookie->getDomain()
            );
            $response->headers->setCookie(new Cookie(
                $cookie->getName(),
                $cookie->getValue(),
                $cookie->getExpiresTime(),
                $cookie->getPath() ?? '/',
                $cookie->getDomain(),
                $isSecure || $cookie->isSecure(),   // Secure: enforce on HTTPS, honour if already set
                true,                                // HttpOnly: always on
                $cookie->isRaw(),
                $cookie->getSameSite() ?? Cookie::SAMESITE_LAX  // SameSite: default Lax
            ));
        }

        return $response;
    }
}
