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
        RoleValue::CUSTOM_VALUE_EDIT,
        RoleValue::CUSTOM_VALUE_VIEW,
    ];
    const VALUE = [
        RoleValue::CUSTOM_VALUE_EDIT,
        RoleValue::CUSTOM_VALUE_VIEW,
    ];
    const PLUGIN = [
        RoleValue::PLUGIN_ACCESS,
        RoleValue::PLUGIN_SETTING,
    ];

    public static function getRoleType($autority_type){
        return static::values()[strtoupper($autority_type)]->value;
    }
}
