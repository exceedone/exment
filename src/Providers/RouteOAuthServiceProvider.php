<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Auth\ApiKeyGrant;
use Exceedone\Exment\Auth\PasswordGrant;
use Exceedone\Exment\Enums\SystemTableName;
use Laravel\Passport\Bridge;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use League\OAuth2\Server\AuthorizationServer;
use Laravel\Passport\Passport;

class RouteOAuthServiceProvider extends ServiceProvider
{
    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        if (canConnection() && hasTable(SystemTableName::SYSTEM) && System::api_available()) {
            $this->forAuthorization();
            $this->forAccessTokens();
            $this->forTransientTokens();
            $this->forClients();
            $this->forPersonalAccessTokens();
        }
    }

    /**
     * Register the routes needed for authorization.
     *
     * @return void
     */
    protected function forAuthorization()
    {
        Route::group($this->getOauthWebOptions(), function ($router) {
            $router->get('/authorize', [
                'uses' => 'AuthorizationController@authorize',
            ]);

            $router->post('/authorize', [
                'uses' => 'ApproveAuthorizationController@approve',
            ]);

            $router->delete('/authorize', [
                'uses' => 'DenyAuthorizationController@deny',
            ]);
        });
    }

    /**
     * Register the routes for retrieving and issuing access tokens.
     *
     * @return void
     */
    protected function forAccessTokens()
    {
        Route::group($this->getOauthAnonymousOptions(), function ($router) {
            $router->post('/token', [
                'uses' => 'AccessTokenController@issueToken',
            ]);
        });

        Route::group($this->getOauthDefaultOptions(), function ($router) {
            $router->get('/tokens', [
                'uses' => 'AuthorizedAccessTokenController@forUser',
            ]);

            $router->delete('/tokens/{token_id}', [
                'uses' => 'AuthorizedAccessTokenController@destroy',
            ]);
        });
    }

    /**
     * Register the routes needed for refreshing transient tokens.
     *
     * @return void
     */
    protected function forTransientTokens()
    {
        Route::group($this->getOauthAnonymousOptions(), function ($router) {
            $router->get('/token/refresh', [
                'uses' => 'TransientTokenController@refresh',
            ]);
        });
    }

    /**
     * Register the routes needed for managing clients.
     *
     * @return void
     */
    protected function forClients()
    {
        Route::group($this->getOauthDefaultOptions(), function ($router) {
            $router->get('/clients', [
                'uses' => 'ClientController@forUser',
            ]);

            $router->post('/clients', [
                'uses' => 'ClientController@store',
            ]);

            $router->put('/clients/{client_id}', [
                'uses' => 'ClientController@update',
            ]);

            $router->delete('/clients/{client_id}', [
                'uses' => 'ClientController@destroy',
            ]);
        });
    }

    /**
     * Register the routes needed for managing personal access tokens.
     *
     * @return void
     */
    protected function forPersonalAccessTokens()
    {
        Route::group($this->getOauthDefaultOptions(), function ($router) {
            $router->get('/scopes', [
                'uses' => 'ScopeController@all',
            ]);

            $router->get('/personal-access-tokens', [
                'uses' => 'PersonalAccessTokenController@forUser',
            ]);

            $router->post('/personal-access-tokens', [
                'uses' => 'PersonalAccessTokenController@store',
            ]);

            $router->delete('/personal-access-tokens/{token_id}', [
                'uses' => 'PersonalAccessTokenController@destroy',
            ]);
        });
    }

    protected function getOauthDefaultOptions()
    {
        return [
            'prefix' => url_join(config('admin.route.prefix'), 'oauth'),
            'namespace' => '\Laravel\Passport\Http\Controllers',
            'middleware' => 'adminapi'
        ];
    }
    protected function getOauthAnonymousOptions()
    {
        return [
            'prefix' => url_join(config('admin.route.prefix'), 'oauth'),
            'namespace' => '\Laravel\Passport\Http\Controllers',
            'middleware' => ['adminapi_anonymous'],
        ];
    }
    protected function getOauthWebOptions()
    {
        return [
            'prefix' => url_join(config('admin.route.prefix'), 'oauth'),
            'namespace' => '\Laravel\Passport\Http\Controllers',
            'middleware' => ['adminweb', 'adminapioauth'],
        ];
    }

    public function boot()
    {
        parent::boot();

        if (canConnection() && hasTable(SystemTableName::SYSTEM) && System::api_available()) {
            app(AuthorizationServer::class)->enableGrantType(
                $this->makeApiKeyGrant(),
                Passport::tokensExpireIn()
            );

            app(AuthorizationServer::class)->enableGrantType(
                $this->makePasswordGrant(),
                Passport::tokensExpireIn()
            );
        }
    }

    protected function makeApiKeyGrant()
    {
        $grant = new ApiKeyGrant(
            $this->app->make(RefreshTokenRepository::class)
        );

        $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());

        return $grant;
    }

    protected function makePasswordGrant()
    {
        $grant = new PasswordGrant(
            $this->app->make(Bridge\UserRepository::class),
            $this->app->make(Bridge\RefreshTokenRepository::class)
        );

        $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());

        return $grant;
    }
}
