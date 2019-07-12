<?php

namespace Exceedone\Exment\Enums;

class Permission extends EnumBase
{
    const SYSTEM = 'system';
    const CUSTOM_TABLE = 'custom_table';
    const CUSTOM_FORM = 'custom_form';
    const CUSTOM_VIEW = 'custom_view';
    const CUSTOM_VALUE_EDIT_ALL = 'custom_value_edit_all';
    const CUSTOM_VALUE_VIEW_ALL = 'custom_value_view_all';
    const CUSTOM_VALUE_ACCESS_ALL = 'custom_value_access_all';
    const CUSTOM_VALUE_EDIT = 'custom_value_edit';
    const CUSTOM_VALUE_VIEW = 'custom_value_view';
    const CUSTOM_VALUE_ACCESS = 'custom_value_access';
    const PLUGIN_ACCESS = 'plugin_access';
    const PLUGIN_SETTING = 'plugin_setting';


    public const AVAILABLE_ACCESS_CUSTOM_VALUE = [self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_ACCESS_ALL, self::CUSTOM_VALUE_EDIT, self::CUSTOM_VALUE_VIEW];
    public const AVAILABLE_VIEW_CUSTOM_VALUE = [self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_EDIT, self::CUSTOM_VALUE_VIEW];
    public const AVAILABLE_EDIT_CUSTOM_VALUE = [self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_EDIT];
    public const AVAILABLE_ALL_CUSTOM_VALUE = [self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_ACCESS_ALL];


    public static function getSystemRolePermissions(){
        return [
            self::SYSTEM,
            self::CUSTOM_VALUE_EDIT_ALL,
        ];
    }

    public static function getMasterRolePermissions(){
        return [
            self::CUSTOM_TABLE,
            self::CUSTOM_VIEW,
            self::CUSTOM_VALUE_EDIT_ALL,
            self::CUSTOM_VALUE_VIEW_ALL,
        ];
    }
    public static function getTableRolePermissions(){
        return [
            self::CUSTOM_TABLE,
            self::CUSTOM_VIEW,
            self::CUSTOM_VALUE_EDIT_ALL,
            self::CUSTOM_VALUE_VIEW_ALL,
            self::CUSTOM_VALUE_ACCESS_ALL,
            self::CUSTOM_VALUE_EDIT,
            self::CUSTOM_VALUE_VIEW,
        ];
    }
}
