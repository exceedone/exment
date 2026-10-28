<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Providers\RouteServiceProvider;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * SSO routes as a guest, asking for a provider that is not configured.
 *
 * RouteServiceProvider::mapExmentAnonymousWebRotes() registers the OAuth/SAML routes only when
 * an active OAuth/SAML login setting exists at boot, and the test seed has none. So setUp()
 * creates one of each and runs that same method again, which registers the routes exactly as
 * the source does (routes already registered are overwritten, not duplicated).
 *
 * AuthOAuthController/AuthSamlController wrap the "find the provider, log in" flow in a broad
 * try/catch that turns any problem (including their own abort(404) for an unknown provider)
 * into a redirect back to the login screen, so none of these can 500.
 *
 * oauth/token/refresh is not covered: Laravel Passport's TransientTokenController::refresh()
 * runs on a route without auth middleware ('adminapi_anonymous', see
 * RouteOAuthServiceProvider::getOauthAnonymousOptions()) and calls getAuthIdentifier() on
 * the null user of a guest, so it answers 500. The route is set up the same way on master.
 */
class AuthProviderUnconfiguredTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // Deliberately a guest in every test here - no $this->be(...).

        LoginSetting::create([
            'login_view_name' => 'claude coverage oauth',
            'login_type' => LoginType::OAUTH,
            'active_flg' => 1,
            'options' => ['oauth_provider_type' => 'google'],
        ]);
        LoginSetting::create([
            'login_view_name' => 'claude coverage saml',
            'login_type' => LoginType::SAML,
            'active_flg' => 1,
            'options' => ['saml_name' => 'claude-coverage-saml'],
        ]);
        // The boot-time read of the login settings is cached for the request.
        System::clearCache();

        $map = new \ReflectionMethod(RouteServiceProvider::class, 'mapExmentAnonymousWebRotes');
        $map->setAccessible(true);
        $map->invoke(new RouteServiceProvider($this->app));
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testOAuthLoginWithUnknownProviderRedirectsToLogin(): void
    {
        $this->get(admin_url('auth/login/does-not-exist-provider'))
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors('sso_error');
    }

    public function testOAuthCallbackWithUnknownProviderRedirects(): void
    {
        // callback() has no early abort(404) the way getLoginProvider() does -
        // it goes straight to guard()->attempt() with a possibly-null
        // login_setting, so either the catch(\Exception) branch (redirect to
        // admin_url('auth/login')) or the "attempt() returned false" branch
        // (back()) can run. Both are redirects, never a 500.
        $this->get(admin_url('auth/login/does-not-exist-provider/callback'))
            ->assertStatus(302);
    }

    public function testSamlLoginWithUnknownProviderRedirectsToLogin(): void
    {
        $this->get(admin_url('saml/login/does-not-exist-provider'))
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors('sso_error');
    }

    public function testSamlMetadataWithUnknownProviderIs404(): void
    {
        // metadata() has no try/catch around its abort(404), unlike login()/acs() -
        // so this one really does answer 404, not a redirect.
        $this->get(admin_url('saml/login/does-not-exist-provider/metadata'))
            ->assertStatus(404);
    }

    public function testSamlAcsWithUnknownProviderRedirects(): void
    {
        // acs() has two failure branches that both redirect, but to different
        // places: a caught exception goes to admin_url('auth/login'), a clean
        // "attempt() returned false" goes through back() instead. Either way
        // the real behavior is a redirect, never a 500 - that is what this
        // asserts, without pinning down which of the two branches runs.
        $this->post(admin_url('saml/login/does-not-exist-provider/acs'))
            ->assertStatus(302);
    }
}
