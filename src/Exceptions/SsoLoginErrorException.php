<?php

namespace Exceedone\Exment\Exceptions;

use Exceedone\Exment\Enums\SsoLoginErrorType;

class SsoLoginErrorException extends \Exception
{
    // @phpstan-ignore-next-line
    protected $sso_login_error_type;
    // @phpstan-ignore-next-line
    protected $displayMessage;
    // @phpstan-ignore-next-line
    protected $adminMessage;
    // @phpstan-ignore-next-line
    protected $hasAdminError;

    // @phpstan-ignore-next-line
    public function __construct($sso_login_error_type, $displayMessage, $adminMessage = null)
    {
        $this->sso_login_error_type = SsoLoginErrorType::getEnum($sso_login_error_type);
        $this->displayMessage = $displayMessage;
        $this->adminMessage = isset($adminMessage) ? $adminMessage : $displayMessage;
        $this->hasAdminError = isset($adminMessage);

        // for logging message
        $this->message = $this->adminMessage;
    }

    // @phpstan-ignore-next-line
    public function getSsoErrorMessage()
    {
        return $this->displayMessage;
    }

    // @phpstan-ignore-next-line
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
