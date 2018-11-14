<?php

namespace Exceedone\Exment\Auth;

use Illuminate\Support\Arr;
use UnexpectedValueException;
// use Illuminate\Contracts\Auth\UserProvider;
// use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\PasswordBroker;
use Exceedone\Exment\Model\LoginUser;

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

        $user = LoginUser
            ::with('base_user')
            ->whereHas('base_user', function ($query) use ($credentials) {
                $query->where('value->email', array_get($credentials, 'email'));
            })->first();

        if ($user && ! $user instanceof CanResetPasswordContract) {
            throw new UnexpectedValueException('User must implement CanResetPassword interface.');
        }

        return $user;
    }
}
