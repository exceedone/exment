<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\System;

/**
 * joined organization filter type. MultiTenant.
 * Getting User type.
 *
 * @method static JoinedMultiUserFilterType NOT_FILTER()
 * @method static JoinedMultiUserFilterType ALL()
 * @method static JoinedMultiUserFilterType ONLY_UPPER()
 * @method static JoinedMultiUserFilterType ONLY_DOWNER()
 * @method static JoinedMultiUserFilterType ONLY_JOIN()
 */
class JoinedMultiUserFilterType extends EnumBase
{
    public const NOT_FILTER = '-1';

    /**
     * *This "ALL" is upper and downer.*
     * Mistake naming...
     */
    public const ALL = '99';
    public const ONLY_DOWNER = '2';
    public const ONLY_UPPER = '1';
    public const ONLY_JOIN = '0';


    public static function getOptions()
    {
        if (boolval(System::organization_available())) {
            return static::transKeyArray('system.filter_multi_orguser_options');
        }

        return collect([static::NOT_FILTER(), static::ONLY_JOIN()])->mapWithKeys(function ($enum) {
            return [$enum->getValue() => exmtrans("system.filter_multi_user_options." . $enum->lowerKey())];
        })->toArray();
    }
}
