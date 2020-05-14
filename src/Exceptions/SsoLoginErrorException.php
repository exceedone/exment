<?php

namespace Exceedone\Exment\Exceptions;

use Exceedone\Exment\Enums\SsoLoginErrorType;

class SsoLoginErrorException extends \Exception
{
    protected $sso_login_error_type;
    protected $message;
    protected $adminMessage;

    public function __construct($sso_login_error_type, $message, $adminMessage = null)
    {
        $this->sso_login_error_type = SsoLoginErrorType::getEnum($sso_login_error_type);
        $this->message = $message;
        $this->adminMessage = isset($adminMessage) ? $adminMessage : $message;
    }


    public function getSsoErrorMessage(){
        return $this->message;
    }

    public function getSsoAdminErrorMessage(){
        return $this->adminMessage;
    }
}
