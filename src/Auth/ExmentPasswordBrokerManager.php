<?php

namespace Exceedone\Exment\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Exceedone\Exment\Support\Timebox;

class ExmentPasswordBrokerManager extends PasswordBrokerManager
{
    /**
     * Resolve the given broker.
     *
     * @param  string  $name
     * @return \Illuminate\Contracts\Auth\PasswordBroker
     */
    protected function resolve($name)
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new \InvalidArgumentException(
                "Password resetter [{$name}] is not defined."
            );
        }

        $provider = $this->app['auth']->createUserProvider($config['provider']);

        $tokenRepository = $this->createTokenRepository($config);

        return new ExmentPasswordBroker(
            $tokenRepository,
            $provider,
            $this->app['events'],
            $this->app->make(Timebox::class)
        );
    }
}
