<?php

namespace Exceedone\Exment\Exceptions;

use Exceedone\Exment\Enums\SsoLoginErrorType;

class SsoLoginErrorException extends \Exception
{
    protected $sso_login_error_type;
    protected $displayMessage;
    protected $adminMessage;
    protected $hasAdminError;

    public function __construct($sso_login_error_type, $displayMessage, $adminMessage = null)
    {
        $this->sso_login_error_type = SsoLoginErrorType::getEnum($sso_login_error_type);
        $this->displayMessage = $displayMessage;
        $this->adminMessage = isset($adminMessage) ? $adminMessage : $displayMessage;
        $this->hasAdminError = isset($adminMessage);

        // for logging message
        $this->message = $this->adminMessage;
    }

    public function getSsoErrorMessage()
    {
        return $this->displayMessage;
    }

    public function getSsoAdminErrorMessage()
    {
        return $this->adminMessage;
    }

    /**
     * Whether this exception has admin error message.
     *
     * @return boolean
     */
    public function hasAdminError()
    {
        return $this->hasAdminError;
    }
}
