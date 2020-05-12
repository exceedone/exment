<?php
namespace Exceedone\Exment\Services\Login\Pure;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;


/**
 * LoginService
 */
class PureService implements LoginServiceInterface
{
    public static function retrieveByCredential(array $credentials)
    {
        $login_user = null;
        foreach (['email', 'user_code'] as $key) {
            $query = LoginUser::whereHas('base_user', function ($query) use ($key, $credentials) {
                $user = CustomTable::getEloquent(SystemTableName::USER);
                $query->where($user->getIndexColumnName($key), array_get($credentials, 'username'));
            });

            $query->where('login_type', LoginType::PURE);
            $login_user = $query->first();

            if (isset($login_user)) {
                break;
            }
        }
        
        if (isset($login_user)) {
            return $login_user;
        }
        return null;
    }

    /**
     * Validate Credential. Check password.
     *
     * @param Authenticatable $login_user
     * @param array $credentials
     * @return void
     */
    public static function validateCredential(Authenticatable $login_user, array $credentials)
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
