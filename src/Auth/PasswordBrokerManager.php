<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Auth\ExmentPasswordBroker as PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory as FactoryContract;
use InvalidArgumentException;

class PasswordBrokerManager extends \Illuminate\Auth\Passwords\PasswordBrokerManager implements FactoryContract
{
    protected function resolve($name)
    {
        $config = $this->getConfig($name);
        if (is_null($config)) {
            throw new InvalidArgumentException("Password resetter [{$name}] is not defined.");
        }

        return new PasswordBroker(
            $this->createTokenRepository($config),
            $this->app['auth']->createUserProvider($config['provider'])
        );
    }
}
