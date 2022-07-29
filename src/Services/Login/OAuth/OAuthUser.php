<?php

namespace Exceedone\Exment\Services\Login\OAuth;

use Exceedone\Exment\Services\Login\CustomLoginUserBase;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;

/**
 * OAuthUser.
 * For OAuth
 * When get user info from provider, set this model.
 */
class OAuthUser extends CustomLoginUserBase
{
    public $avatar;

    /**
     * token. for oauth
     *
     * @var string
     */
    public $token;

    /**
     * refreshtoken. for oauth
     *
     * @var string
     */
    public $refreshToken;
    public $expiresIn;

    public static function with($provider_name, $provider_user, $isTest = false)
    {
        $user = new OAuthUser();
        $user->provider_name = $provider_name;
        $user->login_type = LoginType::OAUTH;
        $user->login_setting = LoginSetting::getOAuthSetting($provider_name, !$isTest);

        $user->mapping_values['email'] = $provider_user->email;
        $user->mapping_values['user_code'] = $provider_user->id;
        $user->mapping_values['user_name'] = $provider_user->name ?: $provider_user->email;

        $user->avatar = isset($provider_user->avatar) ? $provider_user->avatar : null;
        $user->token = isset($provider_user->token) ? $provider_user->token : null;
        $user->refreshToken = isset($provider_user->refreshToken) ? $provider_user->refreshToken : null;
        $user->expiresIn = isset($provider_user->expiresIn) ? $provider_user->expiresIn : null;

        $user->id = $provider_user->id;

        // find key name for search value
        $user->mapping_user_column = $user->login_setting->getOption('mapping_user_column') ?? 'email';
        $user->login_id = array_get($user->mapping_values, $user->mapping_user_column);

        return $user;
    }
}
