<?php

namespace Exceedone\Exment\Enums;

class EditableUserInfoType extends EnumBase
{
    public const NONE = 'none';
    public const VIEW = 'view';
    public const EDIT = 'edit';

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
