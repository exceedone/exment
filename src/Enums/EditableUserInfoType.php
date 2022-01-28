<?php

namespace Exceedone\Exment\Enums;

class EditableUserInfoType extends EnumBase
{
    const NONE = 'none';
    const VIEW = 'view';
    const EDIT = 'edit';

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
