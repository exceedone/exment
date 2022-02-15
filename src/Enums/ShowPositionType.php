<?php

namespace Exceedone\Exment\Enums;

class ShowPositionType extends EnumBase
{
    public const DEFAULT = 'default';
    public const TOP = 'top';
    public const BOTTOM = 'bottom';
    public const HIDE = 'hide';

    public static function SYSTEM_SETTINGS()
    {
        return [
            static::TOP,
            static::BOTTOM,
            static::HIDE,
        ];
    }
}
