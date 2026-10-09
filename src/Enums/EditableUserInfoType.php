<?php

namespace Exceedone\Exment\Enums;

class EditableUserInfoType extends EnumBase
{
    public const NONE = 'none';
    public const VIEW = 'view';
    public const EDIT = 'edit';

    // @phpstan-ignore-next-line
    public static function showSettingForm($editableType)
    {
        switch ($editableType) {
            case static::VIEW:
            case static::EDIT:
                return true;
        }
        return false;
    }
}
