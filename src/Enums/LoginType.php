<?php

namespace Exceedone\Exment\Enums;

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
}
