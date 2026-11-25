<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Model\UserApiKey;
use Laravel\Passport\TransientToken;

/**
 * The "access token" of a request made with an API key of a user: it answers
 * LoginUser::tokenCan() with the scopes of the key (the scope middleware asks it).
 * It is a TransientToken only for the type LoginUser::withAccessToken() takes:
 * can() is the scopes of the key, not "everything".
 */
class UserApiKeyToken extends TransientToken
{
    public function __construct(protected UserApiKey $key)
    {
    }

    public function key(): UserApiKey
    {
        return $this->key;
    }

    /**
     * @param string $scope
     * @return bool
     */
    public function can(string $scope): bool
    {
        return in_array($scope, (array)$this->key->scopes, true);
    }

    /**
     * @param string $scope
     * @return bool
     */
    public function cant(string $scope): bool
    {
        return !$this->can($scope);
    }
}
