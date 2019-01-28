<?php

namespace Exceedone\Exment\Enums;

class Role extends EnumBase
{
    const SYSTEM = [
        RoleValue::SYSTEM,
        RoleValue::CUSTOM_TABLE,
        RoleValue::CUSTOM_FORM,
        RoleValue::CUSTOM_VIEW,
        RoleValue::CUSTOM_VALUE_EDIT_ALL,
    ];
    const TABLE = [
        RoleValue::CUSTOM_TABLE,
        RoleValue::CUSTOM_FORM,
        RoleValue::CUSTOM_VIEW,
        RoleValue::CUSTOM_VALUE_EDIT_ALL,
        RoleValue::CUSTOM_VALUE_VIEW_ALL,
        RoleValue::CUSTOM_VALUE_ACCESS_ALL,
        RoleValue::CUSTOM_VALUE_EDIT,
        RoleValue::CUSTOM_VALUE_VIEW,
        RoleValue::CUSTOM_VALUE_ACCESS,
    ];
    const VALUE = [
        RoleValue::CUSTOM_VALUE_EDIT,
        RoleValue::CUSTOM_VALUE_VIEW,
    ];
    const PLUGIN = [
        RoleValue::PLUGIN_ACCESS,
        RoleValue::PLUGIN_SETTING,
    ];

    public static function getRoleType($role_type)
    {
        return static::values()[RoleType::getEnum($role_type)->getKey()]->value;
    }
}
