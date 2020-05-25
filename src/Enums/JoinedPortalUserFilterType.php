<?php

namespace Exceedone\Exment\Enums;

/**
 * joined organization filter type.
 * Getting User type
 */
class JoinedPortalUserFilterType extends EnumBase
{
    const NOT_FILTER = '-1';
    
    /**
     * *This "ALL" is upper and downer.*
     * Mistake naming...
     */
    const ALL = '99';
    const ONLY_UPPER = '1';
    const ONLY_DOWNER = '2';
    const ONLY_JOIN = '0';
}
