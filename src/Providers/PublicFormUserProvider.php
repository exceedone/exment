<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\LoginType;

/**
 * For public form user provider.
 */
class PublicFormUserProvider extends \Illuminate\Auth\EloquentUserProvider
{
    /**
     * Retrieve a user by their unique identifier.
     *
     * @param  mixed  $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveById($identifier){
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * @param  mixed  $identifier
     * @param  string  $token
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByToken($identifier, $token){

    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  string  $token
     * @return void
     */
    public function updateRememberToken(Authenticatable $user, $token){
    }

    /**
     * Retrieve a user by the given credentials.
     *
     * @param  array  $credentials
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByCredentials(array $credentials){
        $uuid = array_get($credentials, 'uuid');
        if(!$uuid){
            return null;
        }

        $public_form = PublicForm::where('uuid', $uuid)->first();
        if(!$public_form){
            return null;
        }
        return LoginUser::where('base_user_id', $public_form->proxy_user_id)->first();
    }

    /**
     * Validate a user against the given credentials.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  array  $credentials
     * @return bool
     */
    public function validateCredentials(Authenticatable $user, array $credentials){

    }
}
