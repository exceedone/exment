<?php

namespace Exceedone\Exment\Enums;

class DashboardBoxType extends EnumBase
{
    const SYSTEM = 'system';
    const LIST = 'list';
    const CHART = 'chart';

    public static function DASHBOARD_BOX_TYPE_OPTIONS(){
        return 
        [
            ['dashboard_box_type' => self::LIST, 'icon' => 'fa-list'],
            ['dashboard_box_type' => self::SYSTEM, 'icon' => 'fa-wrench'],
            ['dashboard_box_type' => self::CHART, 'icon' => 'fa-bar-chart'],
        ];
    }
}
