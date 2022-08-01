<?php

namespace Exceedone\Exment\Enums;

/**
 * System Table Name List.
 *
 * @method static SystemTableName SYSTEM()
 */
class SystemTableName extends EnumBase
{
    public const SYSTEM = 'systems';
    public const LOGIN_USER = 'login_users';
    public const PLUGIN = 'plugins';
    public const USER = 'user';
    public const ROLE_GROUP = 'role_groups';
    public const ROLE_GROUP_PERMISSION = 'role_group_permissions';
    public const ROLE_GROUP_USER_ORGANIZATION = 'role_group_user_organizations';
    public const ORGANIZATION = 'organization';
    public const COMMENT = 'comment';
    public const MAIL_TEMPLATE = 'mail_template';
    public const MAIL_SEND_LOG = 'mail_send_log';
    public const BASEINFO = 'base_info';
    public const DOCUMENT = 'document';
    public const FILE= 'files';
    public const NOTIFY_HISTORY = 'notify_history';
    public const NOTIFY_HISTORY_USER = 'notify_history_user';
    public const CUSTOM_TABLE = 'custom_tables';
    public const VALUE_AUTHORITABLE = 'value_authoritable';
    public const CUSTOM_VALUE_AUTHORITABLE = 'custom_value_authoritables';
    public const EMAIL_CODE_VERIFY = 'email_code_verifies';
    public const NOTIFY_NAVBAR = 'notify_navbars';
    public const PASSWORD_RESET = 'password_resets';
    public const REVISION = 'revisions';
    public const LOGIN_SETTINGS = 'login_settings';
    public const WORKFLOW_AUTHORITY = 'workflow_authorities';
    public const WORKFLOW = 'workflows';
    public const WORKFLOW_TABLE = 'workflow_tables';
    public const WORKFLOW_ACTION = 'workflow_actions';
    public const WORKFLOW_VALUE = 'workflow_values';
    public const WORKFLOW_VALUE_AUTHORITY = 'workflow_value_authorities';
    public const DATA_SHARE_AUTHORITABLE = 'data_share_authoritables';

    public const VIEW_WORKFLOW_VALUE_UNION = 'view_workflow_value_unions';
    public const VIEW_WORKFLOW_START = 'view_workflow_start';

    public static function SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY()
    {
        return [
            SystemTableName::USER,
            SystemTableName::ORGANIZATION,
            SystemTableName::COMMENT,
            SystemTableName::DOCUMENT,
        ];
    }

    public static function SYSTEM_TABLE_NAME_MASTER()
    {
        return [
            SystemTableName::USER,
            SystemTableName::ORGANIZATION,
            SystemTableName::MAIL_TEMPLATE,
            SystemTableName::MAIL_SEND_LOG,
            SystemTableName::BASEINFO,
        ];
    }
}
