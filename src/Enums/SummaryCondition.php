<?php

namespace Exceedone\Exment\Enums;

class SummaryCondition extends EnumBase
{
    use EnumOptionTrait;

    public const SUM = "1";
    //const AVG = 2;
    public const COUNT = "3";
    public const MIN = "4";
    public const MAX = "5";

    /**
     * @var array[]
     * We should use `const OPTIONS` instead of `protected static $options`.
     */
    protected static $options = [
        "1" => ['id' => "1", 'name' => 'sum', 'numeric' => true, 'countable' => true],
        //2 => ['id' => 2, 'name' => 'avg', 'numeric' => true],
        "3" => ['id' => "3", 'name' => 'count', 'countable' => true],
        "4" => ['id' => "4", 'name' => 'min'],
        "5" => ['id' => "5", 'name' => 'max'],
    ];

    /**
     * Get summary condition name by id.
     *
     * @param int|string|null $value
     * @return string
     */
    public static function getSummaryConditionName($value)
    {
        $option = self::getOption(['id' => $value]);
        return array_get($option, 'name');
    }
}
