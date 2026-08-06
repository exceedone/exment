<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\DataItems\Grid as GridItem;

/**
 * view kind type. default, Aggregate...
 */
class ViewKindType extends EnumBase
{
    public const DEFAULT = "0";
    public const AGGREGATE = "1";
    public const CALENDAR = "2";
    public const FILTER = "3";
    public const PLUGIN = "4";
    public const KANBAN = "5";
    public const ALLDATA = "9";

    /**
     * Whether this view kind can be rendered as a plain data list.
     * Calendar and kanban draw their own layout, so places that expect a
     * table-like list (dashboard list box, chart box...) must exclude them.
     *
     * @param string $view_kind_type
     * @return bool
     */
    public static function isListable($view_kind_type)
    {
        return !in_array($view_kind_type, [static::CALENDAR, static::KANBAN]);
    }

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

    // @phpstan-ignore-next-line
    protected static function acceptApi($view_kind_type, array $acceptTypes)
    {
        $enum = static::getEnum($view_kind_type);
        if (!isset($enum)) {
            return false;
        }

        return in_array($enum, $acceptTypes);
    }


    // @phpstan-ignore-next-line
    public static function getGridItemClassName($view_kind_type)
    {
        switch ($view_kind_type) {
            case static::AGGREGATE:
                return GridItem\SummaryGrid::class;
            case static::CALENDAR:
                return GridItem\CalendarGrid::class;
            case static::KANBAN:
                return GridItem\KanbanGrid::class;
            case static::ALLDATA:
                return GridItem\AllDataGrid::class;
            case static::FILTER:
                return GridItem\FilterGrid::class;
            case static::PLUGIN:
                return GridItem\PluginGrid::class;
            default:
                return GridItem\DefaultGrid::class;
        }
    }
}
