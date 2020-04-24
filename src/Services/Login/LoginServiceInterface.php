<?php

namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;

interface LoginServiceInterface
{
    public static function getTestForm(LoginSetting $login_setting);
}
