<?php

namespace Exceedone\Exment\Enums;

/**
 * joined organization filter type
 */
class JoinedOrgFilterType extends EnumBase
{
    const ALL = '99';
    const ONLY_UPPER = '1';
    const ONLY_DOWNER = '2';
    const ONLY_JOIN = '0';

    /**
     * whether getting upper line organization
     */
    public static function isGetUpper($filterType)
    {
        $enum = static::getEnum($filterType);
        return $enum == static::ONLY_UPPER || $enum == static::ALL;
    }

    /**
     * whether getting down line organization
     */
    public static function isGetDowner($filterType)
    {
        $enum = static::getEnum($filterType);
        return $enum == static::ONLY_DOWNER || $enum == static::ALL;
    }
}
