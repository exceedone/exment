<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Auth\SSOUser;

/**
 * LoginService
 */
class LoginService
{
    public static function setToken(SSOUser $sso_user)
    {
        if($sso_user != LoginType::OAUTH){
            return;
        }

        // set session access key
        session([Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN => [
            'access_token' => $sso_user->token,
            'refresh_token' => $sso_user->refreshToken,
            'provider' => $sso_user->provider_name,
            'expiresIn' =>  $sso_user->expiresIn,
        ]]);
    }

    /**
     * Get access and refresh token
     *
     * @return void
     */
    public static function getToken()
    {
        $session = session(Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN);
        return [
            'access_token' => array_get($session, 'access_token'),
            'refresh_token' => array_get($session, 'refresh_token'),
            'provider' => array_get($session, 'provider')
        ];
    }

    /**
     * Get access token
     *
     * @return void
     */
    public static function getAccessToken()
    {
        return static::getToken()['access_token'];
    }
    
    /**
     * Get refresh token
     *
     * @return void
     */
    public static function getRefreshToken()
    {
        return static::getToken()['refresh_token'];
    }
}
