<?php

namespace Exceedone\Exment\Enums;

class InitializeStatus extends EnumBase
{
    public const LANG = 'lang';
    public const DATABASE = 'database';
    public const SYSTEM_REQUIRE = 'system_require';
    public const INSTALLING = 'installing';
    public const INITIALIZE = 'initialize';
}
