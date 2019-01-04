<?php

namespace Exceedone\Exment\Enums;

class SystemTableName extends EnumBase
{
    const LOGIN_USER = 'login_user';
    const USER = 'user';
    const ORGANIZATION = 'organization';
    const COMMENT = 'comment';
    const MAIL_TEMPLATE = 'mail_template';
    const MAIL_SEND_LOG = 'mail_send_log';
    const BASEINFO = 'base_info';
    const DOCUMENT = 'document';
    const NOTIFY_HISTORY = 'notify_history';
    const NOTIFY_HISTORY_USER = 'notify_history_user';
    const CUSTOM_TABLE = 'custom_tables';
    const SYSTEM_AUTHORITABLE = 'system_authoritable';
    const VALUE_AUTHORITABLE = 'value_authoritable';

    public static function SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY(){
        return [
            SystemTableName::USER,
            SystemTableName::ORGANIZATION,
            SystemTableName::COMMENT,
            SystemTableName::DOCUMENT,
        ];
    }
}
