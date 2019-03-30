<?php

namespace Exceedone\Exment\Enums;

class Role extends EnumBase
{
    const SYSTEM = [
        Permission::SYSTEM,
        Permission::CUSTOM_TABLE,
        Permission::CUSTOM_FORM,
        Permission::CUSTOM_VIEW,
        Permission::CUSTOM_VALUE_EDIT_ALL,
    ];
    const TABLE = [
        Permission::CUSTOM_TABLE,
        Permission::CUSTOM_FORM,
        Permission::CUSTOM_VIEW,
        Permission::CUSTOM_VALUE_EDIT_ALL,
        Permission::CUSTOM_VALUE_VIEW_ALL,
        Permission::CUSTOM_VALUE_ACCESS_ALL,
        Permission::CUSTOM_VALUE_EDIT,
        Permission::CUSTOM_VALUE_VIEW,
        Permission::CUSTOM_VALUE_ACCESS,
    ];
    const VALUE = [
        Permission::CUSTOM_VALUE_EDIT,
        Permission::CUSTOM_VALUE_VIEW,
    ];
    const PLUGIN = [
        Permission::PLUGIN_ACCESS,
        Permission::PLUGIN_SETTING,
    ];

    public static function getRoleType($role_type)
    {
        return static::values()[RoleType::getEnum($role_type)->getKey()]->value;
    }
}
