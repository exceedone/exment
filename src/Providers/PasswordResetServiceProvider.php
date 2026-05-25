<?php

namespace Exceedone\Exment\Providers;

class PasswordResetServiceProvider extends \Illuminate\Auth\Passwords\PasswordResetServiceProvider
{
    protected function registerPasswordBroker()
    {
        // Use instance() instead of singleton() so the binding is placed directly into
        // $app->instances[]. This prevents Laravel's deferred-provider loader from
        // overwriting it with the standard PasswordResetServiceProvider when
        // 'auth.password' is first resolved (isResolved() checks $instances).
        $this->app->instance('auth.password', new \Exceedone\Exment\Auth\PasswordBrokerManager($this->app));

        $this->app->bind('auth.password.broker', function ($app) {
            return $app->make('auth.password')->broker();
        });
    }
}
