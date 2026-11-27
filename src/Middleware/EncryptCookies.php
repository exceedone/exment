<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        // the token of the web installer: set in the request that makes the key of the application
        \Exceedone\Exment\Services\Installer\InstallRunner::COOKIE,
    ];
}
