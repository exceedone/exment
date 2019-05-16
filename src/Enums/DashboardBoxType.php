<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\DashboardBoxItems;

class DashboardBoxType extends EnumBase
{
    const SYSTEM = 'system';
    const LIST = 'list';
    const CHART = 'chart';
    const CALENDAR = 'calendar';

    public static function DASHBOARD_BOX_TYPE_OPTIONS()
    {
        return
        [
            ['dashboard_box_type' => self::LIST, 'icon' => 'fa-list'],
            ['dashboard_box_type' => self::SYSTEM, 'icon' => 'fa-wrench'],
            ['dashboard_box_type' => self::CHART, 'icon' => 'fa-bar-chart'],
            ['dashboard_box_type' => self::CALENDAR, 'icon' => 'fa-calendar'],
        ];
    }

    public function getDashboardBoxItemClass()
    {
        switch ($this) {
            case DashboardBoxType::SYSTEM:
                return DashboardBoxItems\SystemItem::class;
            case DashboardBoxType::LIST:
                return DashboardBoxItems\ListItem::class;
            case DashboardBoxType::CHART:
                return DashboardBoxItems\ChartItem::class;
            case DashboardBoxType::CALENDAR:
                return DashboardBoxItems\CalendarItem::class;
        }
    }
}
