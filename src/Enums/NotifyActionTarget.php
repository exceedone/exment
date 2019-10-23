<?php

namespace Exceedone\Exment\Enums;

class NotifyActionTarget extends EnumBase
{
    const HAS_ROLES = 'has_roles';
    const CREATED_USER = 'created_user';
    const WORK_USER = 'work_user';

    public static function ACTION_TARGET_CUSTOM_TABLE()
    {
        return [
            static::HAS_ROLES,
            static::CREATED_USER,
        ];
    }
    
    public static function ACTION_TARGET_WORKFLOW()
    {
        return [
            static::CREATED_USER,
            static::WORK_USER,
        ];
    }
}
