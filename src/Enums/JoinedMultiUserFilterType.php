<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\System;

/**
 * joined organization filter type. MultiTenant.
 * Getting User type.
 */
class JoinedMultiUserFilterType extends EnumBase
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


    public static function getOptions(){
        if(boolval(System::organization_available())){
            return static::transKeyArray('system.filter_multi_orguser_options');
        } 

        return getTransArray([static::NOT_FILTER()->lowerKey(), static::ONLY_JOIN()->lowerKey()], 'system.filter_multi_user_options');
    }
}
