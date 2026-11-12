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

    // a chart's 期間の単位 only (ChartPeriod), not offered by the view form: the year and its
    // quarter (2026-Q1), the ISO 8601 year and week (2026-W05, a week starting on Monday)
    public const YQ = "yq";
    public const YW = "yw";

    // for sqk sercer
    public const YMDHIS = "ymdhis";

    /**
     * We should use `const OPTIONS` instead of `protected static $options`.
     *
     * @var array[]
     */
    // @phpstan-ignore-next-line
    protected static $options = [
        ['id' => 'y', 'name' => 'y'],
        ['id' => 'ym', 'name' => 'ym'],
        ['id' => 'ymd', 'name' => 'ymd'],
        ['id' => 'm', 'name' => 'm'],
        ['id' => 'd', 'name' => 'd'],
        ['id' => 'w', 'name' => 'w'],
    ];
}
