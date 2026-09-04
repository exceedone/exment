<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Services\Login\LoginService as NewLoginService;

/**
 * OLD : LoginService
 */
class LoginService
{
    // @phpstan-ignore-next-line
    public static function setToken()
    {
        return NewLoginService::setToken();
    }

    /**
     * Get access and refresh token
     *
     * @return array access_token, refresh_token, provider
     */
    // @phpstan-ignore-next-line
    public static function getToken()
    {
        return NewLoginService::getToken();
    }

    /**
     * Get access token
     *
     * @return string|null
     */
    public static function getAccessToken()
    {
        return NewLoginService::getAccessToken();
    }

    /**
     * Get refresh token
     *
     * @return string|null
     */
    public static function getRefreshToken()
    {
        return NewLoginService::getRefreshToken();
    }
}
