<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\LoginType;

class CustomUserProvider extends \Illuminate\Auth\EloquentUserProvider
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
 
    public function retrieveByToken($identifier, $token)
    {
    }
 
    public function updateRememberToken(Authenticatable $user, $token)
    {
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
     * @return ?Authenticatable
     */
    public static function RetrieveByCredential(array $credentials)
    {
        $classname = static::getClassName($credentials);
        if(!isset($classname)){
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
        // has login type, set LoginType::PURE
        if (!array_has($credentials, 'login_type')) {
            $credentials['login_type'] = LoginType::PURE;
        }

        $login_user = null;
        foreach (['email', 'user_code'] as $key) {
            // if user select filtering column, and not mutch, continue
            if(array_has($credentials, 'target_column') && $credentials['target_column'] != $key){
                continue;
            }

            $query = LoginUser::whereHas('base_user', function ($query) use ($key, $credentials) {
                $user = CustomTable::getEloquent(SystemTableName::USER);
                $query->where($user->getIndexColumnName($key), array_get($credentials, 'username'));
            });

            $query->where('login_type', array_get($credentials, 'login_type'));

            // has login provider
            if (array_has($credentials, 'login_provider')) {
                $query->where('login_provider', array_get($credentials, 'login_provider'));
            }

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
    

    public static function ValidateCredential(Authenticatable $login_user, array $credentials)
    {
        if (is_null($login_user)) {
            return false;
        }

        $classname = static::getClassName($credentials);
        if(!isset($classname)){
            return false;
        }

        return $classname::validateCredential($login_user, $credentials);
    }

    protected static function getClassName($credentials){
        // has login type, set LoginType::PURE
        if (!array_key_value_exists('login_type', $credentials)) {
            $credentials['login_type'] = LoginType::PURE;
        }

        return LoginType::getLoginServiceClassName($credentials['login_type']);
    }
}
