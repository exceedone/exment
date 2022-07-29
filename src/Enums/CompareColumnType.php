<?php

namespace Exceedone\Exment\Enums;

use Carbon\Carbon;

class CompareColumnType extends EnumBase
{
    public const SYSTEM_DATE = 'system_date';

    public static function getCompareValue($compare_type)
    {
        switch ($compare_type) {
            case static::SYSTEM_DATE:
                return Carbon::today()->format("Y-m-d");
        }
        return null;
    }
}
