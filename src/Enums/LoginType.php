<?php

namespace Exceedone\Exment\Enums;

class LoginType extends EnumBase
{
    public const PURE = 'pure';
    public const OAUTH = 'oauth';
    public const SAML = 'saml';

    public static function SSO(){
        return [LoginType::OAUTH(), LoginType::SAML()];
    }
}
