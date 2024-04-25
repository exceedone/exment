<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\LoginType;

class LoginUserProvider extends \Illuminate\Auth\EloquentUserProvider
{
    /**
     * Create a new database user provider.
     *
     * @param  \Illuminate\Contracts\Hashing\Hasher  $hasher
     * @param  string  $model
     * @return void
     */
    public function __construct($hasher, $model)
    {
        $this->model = $model;
        $this->hasher = $hasher;
    }

    public function retrieveById($identifier)
    {
        //return \Encore\Admin\Auth\Database\Administrator::find($identifier);
        return LoginUser::find($identifier);
    }

    /**
     * retrieveByCredentials.
     * execute login using each service.
     *
     * @param array $credentials
     * @return ?Authenticatable
     */
    public function retrieveByCredentials(array $credentials)
    {
        return static::RetrieveByCredential($credentials);
    }

    /**
     * retrieveByCredentials.
     * execute login using each service.
     *
     * @param array $credentials
     * @return Authenticatable|null|array
     */
    public static function RetrieveByCredential(array $credentials)
    {
        $credentials = static::getCredentialDefault($credentials);

        // find from database
        if (!$credentials['islogin_from_provider']) {
            return static::findByCredential($credentials);
        }

        // call from database
        $classname = static::getClassName($credentials);
        if (!isset($classname)) {
            return [];
        }

        return $classname::retrieveByCredential($credentials);
    }

    public function validateCredentials(Authenticatable $login_user, array $credentials)
    {
        return static::ValidateCredential($login_user, $credentials);
    }

    /**
     * findByCredentials. Only search from database.
     *
     * @param array $credentials
     * @return ?Authenticatable
     */
    public static function findByCredential(array $credentials)
    {
        $credentials = static::getCredentialDefault($credentials);

        // has login type, set LoginType::PURE
        if (!array_has($credentials, 'login_type')) {
            $credentials['login_type'] = LoginType::PURE;
        }

        $login_user = null;
        foreach (['email', 'user_code'] as $key) {
            // if user select filtering column, and not mutch, continue
            if (array_key_value_exists('target_column', $credentials) && $credentials['target_column'] != $key) {
                continue;
            }

            $query = LoginUser::whereHas('base_user', function ($query) use ($key, $credentials) {
                $column = CustomColumn::getEloquent($key, SystemTableName::USER);
                if (!$column) {
                    $query->whereNotMatch();
                } else {
                    $query->where($column->getQueryKey(), array_get($credentials, 'username'));
                }
            });

            $query->where('login_type', array_get($credentials, 'login_type'));

            // has login provider
            if (array_key_value_exists('provider_name', $credentials)) {
                $query->where('login_provider', array_get($credentials, 'provider_name'));
            }

            $login_user = $query->first();

            if (isset($login_user)) {
                return $login_user;
            }
        }

        return null;
    }

    /**
     * @param Authenticatable $login_user
     * @param array $credentials
     * @return false
     */
    public static function ValidateCredential(Authenticatable $login_user, array $credentials)
    {
        /** @phpstan-ignore-next-line  */
        if (is_null($login_user)) {
            return false;
        }

        $classname = static::getClassName($credentials);
        if (!isset($classname)) {
            return false;
        }

        return $classname::validateCredential($login_user, $credentials);
    }

    protected static function getClassName($credentials)
    {
        // has login type, set LoginType::PURE
        if (!array_key_value_exists('login_type', $credentials)) {
            $credentials['login_type'] = LoginType::PURE;
        }

        return LoginType::getLoginServiceClassName($credentials['login_type']);
    }

    protected static function getCredentialDefault(array $credentials)
    {
        return array_merge(
            [
                'username' => null, // input value
                'login_type' => LoginType::PURE, // login type. pure, oauth, saml...
                'target_column' => null, // search login user target column. if null, email or user_name.
                'provider_name' => null, // login provider name if sso login.
                'islogin_from_provider' => false, // Whether login from sso. if false, only find from exment database.
                'login_setting' => null, // if sso login, target login_setting model.
            ],
            $credentials
        );
    }
}
