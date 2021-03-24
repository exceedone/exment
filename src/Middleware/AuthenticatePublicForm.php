<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Exceedone\Exment\Model\Define;

class AuthenticatePublicForm extends Middleware
{
    /**
     * Determine if the user is logged in to any of the given guards.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $guards
     * @return void
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    public function authenticate($request, array $guards)
    {
        if ($this->auth->guard(Define::AUTHENTICATE_KEY_PUBLIC_FORM)->check()) {
            \Exment::setGuard(Define::AUTHENTICATE_KEY_PUBLIC_FORM);
        } else {
            throw new \Exceedone\Exment\Exceptions\PublicFormNotFoundException();
        }
    }
}
