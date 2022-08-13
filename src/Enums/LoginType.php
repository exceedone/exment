<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Services\Login as LoginServiceRoot;

/**
 * Login Type Difinition.
 *
 * @method static LoginType PURE()
 * @method static LoginType OAUTH()
 * @method static LoginType SAML()
 * @method static LoginType LDAP()
 */
class LoginType extends EnumBase
{
    public const PURE = 'pure';
    public const OAUTH = 'oauth';
    public const SAML = 'saml';
    public const LDAP = 'ldap';

    public static function SETTING()
    {
        return [LoginType::OAUTH(), LoginType::SAML(), LoginType::LDAP()];
    }

    /**
     * Get Login Service Class Name
     *
     * @return string
     */
    public static function getLoginServiceClassName($login_type): string
    {
        switch ($login_type) {
            case LoginType::PURE:
                return LoginServiceRoot\Pure\PureService::class;
            case LoginType::OAUTH:
                return LoginServiceRoot\OAuth\OAuthService::class;
            case LoginType::SAML:
                return LoginServiceRoot\Saml\SamlService::class;
            case LoginType::LDAP:
                return LoginServiceRoot\Ldap\LdapService::class;
        }
        return '';
    }
}
