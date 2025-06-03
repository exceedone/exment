<?php

namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Model\LoginSetting;
use Illuminate\Contracts\Auth\Authenticatable;

interface LoginServiceInterface
{
    /**
     * @param LoginSetting $login_setting
     * @return mixed
     */
    public static function getTestForm(LoginSetting $login_setting);

    /**
     * @param array<mixed> $credentials
     * @return mixed
     */
    public static function retrieveByCredential(array $credentials);

    /**
     * @param Authenticatable $login_user
     * @param array<mixed> $credentials
     * @return mixed
     */
    public static function validateCredential(Authenticatable $login_user, array $credentials);
}
