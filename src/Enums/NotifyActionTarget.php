<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\System;

class NotifyActionTarget extends EnumBase
{
    public const ADMINISTRATOR = 'administrator';
    public const HAS_ROLES = 'has_roles';
    public const CREATED_USER = 'created_user';
    public const WORK_USER = 'work_user';
    public const FIXED_EMAIL = 'fixed_email';
    public const CUSTOM_COLUMN = 'custom_column';
    public const FIXED_USER = 'fixed_user';
    public const FIXED_ORGANIZATION = 'fixed_organization';
    public const ACTION_USER = 'action_user';

    public static function ACTION_TARGET_CUSTOM_TABLE()
    {
        $targets = [
            static::ADMINISTRATOR,
            static::HAS_ROLES,
            static::CREATED_USER,
            static::FIXED_USER,
        ];

        if (System::organization_available()) {
            $targets[] = static::FIXED_ORGANIZATION;
        }

        return $targets;
    }

    public static function ACTION_TARGET_WORKFLOW()
    {
        $targets = [
            static::ADMINISTRATOR,
            static::CREATED_USER,
            static::WORK_USER,
            static::ACTION_USER,
            static::FIXED_USER,
        ];

        if (System::organization_available()) {
            $targets[] = static::FIXED_ORGANIZATION;
        }

        return $targets;
    }
}
