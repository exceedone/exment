<?php

namespace Exceedone\Exment\Enums;

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
