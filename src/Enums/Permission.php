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
    const CUSTOM_VALUE_SHARE = 'custom_value_share';
    const CUSTOM_VALUE_IMPORT = 'custom_value_import';
    const CUSTOM_VALUE_EXPORT = 'custom_value_export';
    const PLUGIN_ACCESS = 'plugin_access';
    const PLUGIN_SETTING = 'plugin_setting';
    const LOGIN_USER = 'login_user';
    const ROLE_GROUP_ALL = 'role_group_all';
    const ROLE_GROUP_PERMISSION = 'role_group_permission';
    const ROLE_GROUP_USER_ORGANIZATION = 'role_group_user_organization';
    const WORKFLOW = 'workflow';


    public const AVAILABLE_ACCESS_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_ACCESS_ALL, self::CUSTOM_VALUE_EDIT, self::CUSTOM_VALUE_VIEW];
    public const AVAILABLE_VIEW_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_EDIT, self::CUSTOM_VALUE_VIEW];
    public const AVAILABLE_EDIT_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_EDIT];
    public const AVAILABLE_ALL_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_ACCESS_ALL];
    public const AVAILABLE_ALL_EDIT_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL];
    public const AVAILABLE_ACCESS_ROLE_GROUP = [self::ROLE_GROUP_ALL, self::ROLE_GROUP_PERMISSION, self::ROLE_GROUP_USER_ORGANIZATION];

    public const SYSTEM_ROLE_PERMISSIONS = [
        self::CUSTOM_TABLE,
        self::CUSTOM_VALUE_EDIT_ALL,
        self::LOGIN_USER,
        self::WORKFLOW,
    ];
    public const ROLE_GROUP_ROLE_PERMISSION = [
        self::ROLE_GROUP_ALL,
        self::ROLE_GROUP_PERMISSION,
        self::ROLE_GROUP_USER_ORGANIZATION
    ];
    
    public const MASTER_ROLE_PERMISSION = [
        self::CUSTOM_TABLE,
        self::CUSTOM_VIEW,
        self::CUSTOM_VALUE_EDIT_ALL,
        self::CUSTOM_VALUE_VIEW_ALL,
        self::CUSTOM_VALUE_IMPORT,
        self::CUSTOM_VALUE_EXPORT,
    ];
    
    public const TABLE_ROLE_PERMISSION = [
        self::CUSTOM_TABLE,
        self::CUSTOM_VIEW,
        self::CUSTOM_VALUE_EDIT_ALL,
        self::CUSTOM_VALUE_VIEW_ALL,
        self::CUSTOM_VALUE_EDIT,
        self::CUSTOM_VALUE_VIEW,
        self::CUSTOM_VALUE_SHARE,
        self::CUSTOM_VALUE_IMPORT,
        self::CUSTOM_VALUE_EXPORT,
    ];
}
