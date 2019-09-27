<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Define;
use Symfony\Component\HttpFoundation\Response;

/**
 * LoginService
 */
class LoginService
{
    public static function setToken($login_provider, $provider_user){
        // set session access key
        session([Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN => [
            'access_token' => $provider_user->token,
            'refresh_token' => $provider_user->refreshToken,
            'provider' => $login_provider,
            'expiresIn' =>  $provider_user->expiresIn,
        ]]);
    }

    /**
     * Get access and refresh token
     *
     * @return void
     */
    public static function getToken(){
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
    public static function getRefreshToken(){
        return static::getToken()['refresh_token'];
    }
    
    /**
     * get Socialite Provider
     */
    public static function getSocialiteProvider(string $login_provider)
    {
        if(is_null(config("services.$login_provider.redirect"))){
            config(["services.$login_provider.redirect" => admin_urls("auth", "login", $login_provider, "callback")]);
        }
        
        $scope = config("services.$login_provider.scope", []);
        if(!empty($scope)){
            $scope = is_string($scope) ? explode(',', $scope) : $scope;
        }
        
        return \Socialite::with($login_provider)
            ->scopes($scope)
            //->stateless();
            ;
    }
}
