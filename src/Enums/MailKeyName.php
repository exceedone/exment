<?php

namespace Exceedone\Exment\Enums;

class MailKeyName extends EnumBase
{
    const CREATE_USER = 'create_user';
    const RESET_PASSWORD = 'reset_password';
    const RESET_PASSWORD_ADMIN = 'reset_password_admin';
    const VERIFY_2FACTOR = 'verify_2factor';
    const VERIFY_2FACTOR_GOOGLE = 'verify_2factor_google';
    const VERIFY_2FACTOR_SYSTEM = 'verify_2factor_system';
    const TIME_NOTIFY = 'time_notify';
    const DATA_SAVED_NOTIFY = 'data_saved_notify';
    const PASSWORD_NOTIFY = 'password_notify';
    const PASSWORD_NOTIFY_HEADER = 'password_notify_header';
    const MAIL_FOOTER = 'mail_footer';
}
