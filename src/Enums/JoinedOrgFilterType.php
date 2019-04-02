<?php

namespace Exceedone\Exment\Enums;

/**
 * joined organization filter type
 */
class JoinedOrgFilterType extends EnumBase
{
    const ONLY_JOIN = 'only_join';
    const ONLY_UPPER = 'only_upper';
    const ONLY_DOWNER = 'only_downer';
    const ALL = 'all';

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
