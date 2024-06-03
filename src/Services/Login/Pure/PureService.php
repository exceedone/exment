<?php

namespace Exceedone\Exment\Services\Login\Pure;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Exceedone\Exment\Providers\LoginUserProvider;

/**
 * LoginService
 */
class PureService implements LoginServiceInterface
{
    public static function retrieveByCredential(array $credentials)
    {
        return LoginUserProvider::findByCredential($credentials);
    }

    /**
     * Validate Credential. Check password.
     *
     * @param Authenticatable|null $login_user
     * @param array $credentials
     * @return bool
     */
    public static function validateCredential(?Authenticatable $login_user, array $credentials)
    {
        if (is_null($login_user)) {
            return false;
        }

        $password = $login_user->password;
        $credential_password = array_get($credentials, 'password');
        // Verify the user with the username password in $ credentials, return `true` or `false`
        return !is_null($credential_password) && Hash::check($credential_password, $password);
    }

    public static function getTestForm(LoginSetting $login_setting)
    {
        return null;
    }
}
