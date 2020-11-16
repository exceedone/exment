<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\DataItems\Grid as GridItem;

/**
 * view kind type. default, Aggregate...
 */
class ViewKindType extends EnumBase
{
    const DEFAULT = "0";
    const AGGREGATE = "1";
    const CALENDAR = "2";
    const FILTER = "3";
    const ALLDATA = "9";

    /**
     * Accept view kind type for datalist
     *
     * @param string $view_kind_type
     * @return bool
     */
    public static function acceptApiList($view_kind_type)
    {
        return static::acceptApi($view_kind_type, [static::DEFAULT, static::ALLDATA, static::AGGREGATE]);
    }

    /**
     * Accept view kind type for datalist
     *
     * @param string $view_kind_type
     * @return bool
     */
    public static function acceptApiData($view_kind_type)
    {
        return static::acceptApi($view_kind_type, [static::DEFAULT, static::ALLDATA]);
    }

    protected static function acceptApi($view_kind_type, array $acceptTypes)
    {
        $enum = static::getEnum($view_kind_type);
        if (!isset($enum)) {
            return false;
        }

        return in_array($enum, $acceptTypes);
    }

    
    public static function getGridItemClassName($view_kind_type)
    {
        switch ($view_kind_type) {
            case static::AGGREGATE:
                return GridItem\SummaryGrid::class;
            case static::CALENDAR:
                return GridItem\CalendarGrid::class;
            case static::ALLDATA:
                return GridItem\AllDataGrid::class;
            case static::FILTER:
                return GridItem\FilterGrid::class;
            default:
                return GridItem\DefaultGrid::class;
        }
    }
}
