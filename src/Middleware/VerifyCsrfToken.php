<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Foundation\Application;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifier;

class VerifyCsrfToken extends BaseVerifier
{
    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @param  \Illuminate\Contracts\Encryption\Encrypter  $encrypter
     * @return void
     */
    public function __construct(Application $app, Encrypter $encrypter)
    {
        $this->except = [
            admin_base_path('login_setting/*/testcallback'),
            admin_base_path('saml/login/*/acs'),
            // Safety-check mail-fallback answer page: the signed URL itself is the
            // authenticator — whoever holds the link can already POST directly with
            // a hand-built form regardless of a CSRF token, so CSRF adds nothing
            // here. A 419 dead-end is unacceptable when the user is trying to
            // answer "I need help" during an emergency.
            admin_base_path('safety/answer'),
        ];

        parent::__construct($app, $encrypter);
    }
}
