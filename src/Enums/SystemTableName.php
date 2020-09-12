<?php

namespace Exceedone\Exment\Enums;

/**
 * System Table Name List.
 *
 * @method static SystemTableName SYSTEM()
 */
class SystemTableName extends EnumBase
{
    const SYSTEM = 'systems';
    const LOGIN_USER = 'login_users';
    const PLUGIN = 'plugins';
    const USER = 'user';
    const ROLE_GROUP = 'role_groups';
    const ROLE_GROUP_PERMISSION = 'role_group_permissions';
    const ROLE_GROUP_USER_ORGANIZATION = 'role_group_user_organizations';
    const ORGANIZATION = 'organization';
    const COMMENT = 'comment';
    const MAIL_TEMPLATE = 'mail_template';
    const MAIL_SEND_LOG = 'mail_send_log';
    const BASEINFO = 'base_info';
    const DOCUMENT = 'document';
    const NOTIFY_HISTORY = 'notify_history';
    const NOTIFY_HISTORY_USER = 'notify_history_user';
    const CUSTOM_TABLE = 'custom_tables';
    const VALUE_AUTHORITABLE = 'value_authoritable';
    const CUSTOM_VALUE_AUTHORITABLE = 'custom_value_authoritables';
    const EMAIL_CODE_VERIFY = 'email_code_verifies';
    const NOTIFY_NAVBAR = 'notify_navbars';
    const PASSWORD_RESET = 'password_resets';
    const REVISION = 'revisions';
    const LOGIN_SETTINGS = 'login_settings';
    const WORKFLOW_AUTHORITY = 'workflow_authorities';
    const WORKFLOW = 'workflows';
    const WORKFLOW_TABLE = 'workflow_tables';
    const WORKFLOW_ACTION = 'workflow_actions';
    const WORKFLOW_VALUE = 'workflow_values';
    const WORKFLOW_VALUE_AUTHORITY = 'workflow_value_authorities';
    const DATA_SHARE_AUTHORITABLE = 'data_share_authoritables';

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
