<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Services\LoginService as NewLoginService;
use Exceedone\Exment\Auth\CustomLoginUserBase;

/**
 * OLD : LoginService
 */
class LoginService
{
    public static function setToken(CustomLoginUserBase $custom_login_user)
    {
        return NewLoginService::setToken($custom_login_user);
    }

    /**
     * Get access and refresh token
     *
     * @return void
     */
    public static function getToken()
    {
        return NewLoginService::getToken();
    }

    /**
     * Get access token
     *
     * @return void
     */
    public static function getAccessToken()
    {
        return NewLoginService::getAccessToken();
    }
    
    /**
     * Get refresh token
     *
     * @return void
     */
    public static function getRefreshToken()
    {
        return NewLoginService::getRefreshToken();
    }
}
