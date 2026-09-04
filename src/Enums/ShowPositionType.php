<?php

namespace Exceedone\Exment\Enums;

class ShowPositionType extends EnumBase
{
    public const DEFAULT = 'default';
    public const TOP = 'top';
    public const BOTTOM = 'bottom';
    public const HIDE = 'hide';

    // @phpstan-ignore-next-line
    public static function SYSTEM_SETTINGS()
    {
        return [
            static::TOP,
            static::BOTTOM,
            static::HIDE,
        ];
    }
}
