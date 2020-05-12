<?php

namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Model\LoginSetting;
use Illuminate\Contracts\Auth\Authenticatable;

interface LoginServiceInterface
{
    public static function getTestForm(LoginSetting $login_setting);

    public static function retrieveByCredential(array $credentials);

    public static function validateCredential(Authenticatable $login_user, array $credentials);
}
