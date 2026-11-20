<?php

namespace Exceedone\Exment\Services\Login\OAuth;

use Exceedone\Exment\Services\Login\CustomLoginUserBase;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\SsoLoginErrorType;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;

/**
 * OAuthUser.
 * For OAuth
 * When get user info from provider, set this model.
 */
class OAuthUser extends CustomLoginUserBase
{
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public $expiresIn;

    // @phpstan-ignore-next-line
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

        // The user is found by the e-mail address the provider gives. A provider
        // that says the address is not confirmed lets anyone write any address,
        // so such an address does not log in as the user who has it.
        if ($user->mapping_user_column == 'email' && static::isEmailUnverified($provider_user)) {
            throw new SsoLoginErrorException(SsoLoginErrorType::PROVIDER_ERROR, exmtrans('login.sso_email_unverified'));
        }

        return $user;
    }

    /**
     * Whether the provider says the e-mail address is not confirmed
     * (OpenID Connect "email_verified", Google "verified_email"). Only an
     * explicit "no" counts: a provider that does not tell is taken as before.
     *
     * @param mixed $provider_user
     */
    protected static function isEmailUnverified($provider_user): bool
    {
        $raw = is_object($provider_user) && isset($provider_user->user) ? (array)$provider_user->user : [];
        foreach (['email_verified', 'verified_email'] as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if ($value === false || $value === 0 || (is_string($value) && in_array(strtolower($value), ['false', '0'], true))) {
                return true;
            }
        }
        return false;
    }
}
