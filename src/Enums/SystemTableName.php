<?php

namespace Exceedone\Exment\Enums;

class SystemTableName extends EnumBase
{
    const SYSTEM = 'systems';
    const LOGIN_USER = 'login_user';
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
    const EMAIL_CODE_VERIFY = 'email_code_verifies';

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
