<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Auth\ExmentPasswordBroker as PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory as FactoryContract;
use Illuminate\Support\Timebox;
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
            // @phpstan-ignore-next-line
            $this->app['auth']->createUserProvider($config['provider']),
            // @phpstan-ignore-next-line
            $this->app['events'],
            $this->app->make(Timebox::class)
        );
    }
}
