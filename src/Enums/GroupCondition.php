<?php

namespace Exceedone\Exment\Enums;

class GroupCondition extends EnumBase
{
    use EnumOptionTrait;
    
    const Y = "y";
    const YM = "ym";
    const YMD = "ymd";
    const M = "m";
    const D = "d";

    protected static $options = [
        'y' => ['id' => 'y', 'name' => 'y', 'sqlformat' => '%Y'],
        'ym' => ['id' => 'ym', 'name' => 'ym', 'sqlformat' => '%Y-%m'],
        'ymd' => ['id' => 'ymd', 'name' => 'ymd', 'sqlformat' => '%Y-%m-%d'],
        'm' => ['id' => 'm', 'name' => 'm', 'sqlformat' => '%m'],
        'd' => ['id' => 'd', 'name' => 'd', 'sqlformat' => '%d'],
    ];

    public static function getGroupCondition($value)
    {
        $option = self::getOption(['id' => $value]);
        if (array_get($option, 'countable')) {
            return self::getEnum(self::SUM)->lowerKey();
        }
        return self::getEnum(self::MIN)->lowerKey();
    }
}
