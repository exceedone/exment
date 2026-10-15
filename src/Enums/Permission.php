<?php

namespace Exceedone\Exment\Enums;

class Permission extends EnumBase
{
    public const SYSTEM = 'system';
    public const CUSTOM_TABLE = 'custom_table';
    public const CUSTOM_FORM = 'custom_form';
    public const CUSTOM_FORM_PUBLIC = 'custom_form_public';
    public const CUSTOM_VIEW = 'custom_view';
    public const CUSTOM_VALUE_EDIT_ALL = 'custom_value_edit_all';
    public const CUSTOM_VALUE_VIEW_ALL = 'custom_value_view_all';
    public const CUSTOM_VALUE_ACCESS_ALL = 'custom_value_access_all';
    public const CUSTOM_VALUE_EDIT = 'custom_value_edit';
    public const CUSTOM_VALUE_VIEW = 'custom_value_view';
    //const CUSTOM_VALUE_ACCESS = 'custom_value_access';
    public const CUSTOM_VALUE_SHARE = 'custom_value_share';
    public const CUSTOM_VALUE_VIEW_TRASHED = 'custom_value_view_trashed';
    public const CUSTOM_VALUE_IMPORT = 'custom_value_import';
    public const CUSTOM_VALUE_EXPORT = 'custom_value_export';
    public const PLUGIN_ALL = 'plugin_all';
    public const PLUGIN_ACCESS = 'plugin_access';
    public const PLUGIN_SETTING = 'plugin_setting';
    public const LOGIN_USER = 'login_user';
    public const FILTER_MULTIUSER_ALL = 'filter_multiuser_all';
    public const ROLE_GROUP_ALL = 'role_group_all';
    public const ROLE_GROUP_PERMISSION = 'role_group_permission';
    public const ROLE_GROUP_USER_ORGANIZATION = 'role_group_user_organization';
    public const WORKFLOW = 'workflow';
    public const API_ALL = 'api_all';
    public const API = 'api';
    public const DATA_SHARE_EDIT = 'data_share_edit';
    public const DATA_SHARE_VIEW = 'data_share_view';


    public const AVAILABLE_ACCESS_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_ACCESS_ALL, self::CUSTOM_VALUE_EDIT, self::CUSTOM_VALUE_VIEW];
    public const AVAILABLE_VIEW_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_EDIT, self::CUSTOM_VALUE_VIEW];
    public const AVAILABLE_EDIT_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_EDIT];
    public const AVAILABLE_ALL_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_ACCESS_ALL];
    public const AVAILABLE_ALL_EDIT_CUSTOM_VALUE = [self::CUSTOM_TABLE, self::CUSTOM_VALUE_EDIT_ALL];
    public const AVAILABLE_ACCESS_ROLE_GROUP = [self::ROLE_GROUP_ALL, self::ROLE_GROUP_PERMISSION, self::ROLE_GROUP_USER_ORGANIZATION];
    public const AVAILABLE_API = [self::API_ALL, self::API];

    // access custom form
    public const AVAILABLE_CUSTOM_FORM = [self::CUSTOM_TABLE, self::CUSTOM_FORM, self::CUSTOM_FORM_PUBLIC];
    // edit custom form
    public const EDIT_CUSTOM_FORM = [self::CUSTOM_TABLE, self::CUSTOM_FORM];
    // edit custom form public
    public const EDIT_CUSTOM_FORM_PUBLIC = [self::CUSTOM_TABLE, self::CUSTOM_FORM_PUBLIC];
    // import or expoert custom value
    public const IMPORT_EXPORT = [self::CUSTOM_VALUE_IMPORT, self::CUSTOM_VALUE_EXPORT];

    public const SYSTEM_ROLE_PERMISSIONS = [
        self::CUSTOM_TABLE,
        self::CUSTOM_VALUE_EDIT_ALL,
        self::LOGIN_USER,
        self::WORKFLOW,
        self::API_ALL,
        self::API,
        self::PLUGIN_ALL,

        // appending dynamic
        //self::FILTER_MULTIUSER_ALL,
    ];

    public const ROLE_GROUP_ROLE_PERMISSION = [
        self::ROLE_GROUP_ALL,
        self::ROLE_GROUP_PERMISSION,
        self::ROLE_GROUP_USER_ORGANIZATION,
    ];

    public const ROLE_GROUP_PLUGIN_PERMISSION = [
        self::PLUGIN_SETTING,
        self::PLUGIN_ACCESS,
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
        self::CUSTOM_FORM,
        self::CUSTOM_FORM_PUBLIC,
        self::CUSTOM_VIEW,
        self::CUSTOM_VALUE_EDIT_ALL,
        self::CUSTOM_VALUE_VIEW_ALL,
        self::CUSTOM_VALUE_ACCESS_ALL,
        self::CUSTOM_VALUE_EDIT,
        self::CUSTOM_VALUE_VIEW,
        self::CUSTOM_VALUE_SHARE,
        self::CUSTOM_VALUE_IMPORT,
        self::CUSTOM_VALUE_EXPORT,
        self::CUSTOM_VALUE_VIEW_TRASHED,
    ];
}
