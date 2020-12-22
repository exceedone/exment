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
    const W = "w";
    
    // for sqk sercer
    const YMDHIS = "ymdhis";

    protected static $options = [
        ['id' => 'y', 'name' => 'y'],
        ['id' => 'ym', 'name' => 'ym'],
        ['id' => 'ymd', 'name' => 'ymd'],
        ['id' => 'm', 'name' => 'm'],
        ['id' => 'd', 'name' => 'd'],
        ['id' => 'w', 'name' => 'w'],
    ];
}
