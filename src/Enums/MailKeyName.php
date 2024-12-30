<?php

namespace Exceedone\Exment\Enums;

class MailKeyName extends EnumBase
{
    public const CREATE_USER = 'create_user';
    public const RESET_PASSWORD = 'reset_password';
    public const RESET_PASSWORD_ADMIN = 'reset_password_admin';
    public const VERIFY_2FACTOR = 'verify_2factor';
    public const VERIFY_2FACTOR_GOOGLE = 'verify_2factor_google';
    public const VERIFY_2FACTOR_SYSTEM = 'verify_2factor_system';
    public const TIME_NOTIFY = 'time_notify';
    public const DATA_SAVED_NOTIFY = 'data_saved_notify';
    public const PASSWORD_NOTIFY = 'password_notify';
    public const PASSWORD_NOTIFY_HEADER = 'password_notify_header';
    public const MAIL_FOOTER = 'mail_footer';
    public const WORKFLOW_NOTIFY = 'workflow_notify';
    public const PUBLICFORM_COMPLETE_USER = 'publicform_complete_user';
    public const PUBLICFORM_COMPLETE_ADMIN = 'publicform_complete_admin';
    public const PUBLICFORM_ERROR = 'publicform_error';
    public const SENDMAIL_ERROR = 'sendmail_error';
}
