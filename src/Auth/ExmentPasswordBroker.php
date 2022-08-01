<?php

namespace Exceedone\Exment\Auth;

use Illuminate\Support\Arr;
use UnexpectedValueException;
// use Illuminate\Contracts\Auth\UserProvider;
// use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\PasswordBroker;
use Exceedone\Exment\Providers\LoginUserProvider;

class ExmentPasswordBroker extends PasswordBroker
{
    /**
     * Get the user for the given credentials.
     *
     * @param  array  $credentials
     * @return \Illuminate\Contracts\Auth\CanResetPassword|null
     *
     * @throws \UnexpectedValueException
     */
    public function getUser(array $credentials)
    {
        $credentials = Arr::except($credentials, ['token']);

        // $user = $this->users->retrieveByCredentials($credentials);
        $user = LoginUserProvider::findByCredential($credentials);

        if ($user && ! $user instanceof CanResetPasswordContract) {
            throw new UnexpectedValueException('User must implement CanResetPassword interface.');
        }

        return $user;
    }
}
