<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Illuminate\Contracts\Auth\StatefulGuard;

class LogServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(function () {
                return app('auth.driver');
            });
        // $this->app->singleton(\App\Services\TraceCollector::class, function ($app) {
        //     return new \App\Services\TraceCollector();
        // });
    }

    public function boot()
    {
        // app()->resolving(function ($object, $app) {
        //     \Log::debug('resolve', [
        //         'class' => is_object($object) ? get_class($object) : gettype($object)
        //     ]);
        // });
    }

}
