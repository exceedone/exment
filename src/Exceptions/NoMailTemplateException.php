<?php

namespace Exceedone\Exment\Exceptions;

class NoMailTemplateException extends \Exception
{
    public function __construct($mail_key_name)
    {
        parent::__construct("No MailTemplate. Please set mail template. mail_template:$mail_key_name");
    }
}
