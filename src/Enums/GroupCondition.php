<?php

namespace Exceedone\Exment\Enums;

class GroupCondition extends EnumBase
{
    use EnumOptionTrait;

    public const Y = "y";
    public const YM = "ym";
    public const YMD = "ymd";
    public const M = "m";
    public const D = "d";
    public const W = "w";

    // for sqk sercer
    public const YMDHIS = "ymdhis";

    /**
     * We should use `const OPTIONS` instead of `protected static $options`.
     *
     * @var array[]
     */
    protected static $options = [
        ['id' => 'y', 'name' => 'y'],
        ['id' => 'ym', 'name' => 'ym'],
        ['id' => 'ymd', 'name' => 'ymd'],
        ['id' => 'm', 'name' => 'm'],
        ['id' => 'd', 'name' => 'd'],
        ['id' => 'w', 'name' => 'w'],
    ];
}
