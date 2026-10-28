<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Providers\RouteServiceProvider;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

/**
 * SSO with a configured provider: the login test of a login setting (admin/login_setting/{id}/test*),
 * the OAuth login and its callback (admin/auth/login/{provider}), and the SAML metadata and ACS
 * (admin/saml/login/{provider}).
 *
 * The identity providers are outside: a login starts with the redirect to the provider, and the
 * provider's answer is stubbed at the Socialite boundary (the provider's user() call), which is
 * the only part exment does not run itself. The SAML login redirect is left out: OneLogin sends it
 * with header() + exit() (OneLogin\Saml2\Utils::redirect()), which would end PHPUnit.
 */
class SsoLoginCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/auth?';

    /**
     * OneLogin refuses settings without an IdP certificate; nothing here verifies a signature
     * with it (no IdP answer is processed), so it only has to be present.
     */
    private const IDP_CERTIFICATE = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ2xhdWRlQ292ZXJhZ2VUZXN0MAoGCCqGSM49BAMCMBkx\nFzAVBgNVBAMMDmlkcC5leGFtcGxlLmNvbTAeFw0yNjAxMDEwMDAwMDBaFw0zNjAx\n-----END CERTIFICATE-----";

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        // the login settings are cached for the request
        System::clearCache();
        parent::tearDown();
    }

    // the login test of a setting (LoginSettingController) -------------------------

    public function testTheLoginTestModalOfAnOAuthSettingLinksToTheSsoTest(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $setting = $this->createOAuthSetting();

        $response = $this->get(admin_url("login_setting/{$setting->id}/testModal"));

        $response->assertOk()->assertJson(['title' => exmtrans('login.login_test'), 'showSubmit' => false]);
        $body = $response->json('body');
        $this->assertIsString($body);
        // the button that starts the test, and the callback URL to register at the provider
        $this->assertStringContainsString(route('exment.logintest_sso', ['id' => $setting->id]), $body);
        $this->assertStringContainsString(route('exment.logintest_callback', ['id' => $setting->id]), $body);
    }

    public function testTheSsoLoginTestOfAnOAuthSettingStartsAtTheProvider(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $setting = $this->createOAuthSetting();

        $this->assertProviderRedirect(
            $this->get(admin_url("login_setting/{$setting->id}/testSso")),
            route('exment.logintest_callback', ['id' => $setting->id])
        );

        // the test form posts here for LDAP, whose test needs adldap2, a package the CI app does not
        // install; for an OAuth setting the same handler also starts the test at the provider
        $this->assertProviderRedirect(
            $this->post(admin_url("login_setting/{$setting->id}/testForm")),
            route('exment.logintest_callback', ['id' => $setting->id])
        );
    }

    public function testTheOAuthTestCallbackChecksTheUserTheProviderAnswered(): void
    {
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($admin);
        $setting = $this->createOAuthSetting();
        $this->fakeGoogleUser((string) $admin->email);

        $this->get(admin_url("login_setting/{$setting->id}/testcallback"))
            ->assertRedirect(route('exment.login_setting.edit', ['id' => $setting->id]) . '?test_callback=1')
            ->assertSessionHas(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE);

        // the edit page shows this message: the user the provider answered matched an exment user
        $message = session(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE);
        $this->assertIsString($message);
        $this->assertStringContainsString(exmtrans('common.message.success_execute'), $message);
        $this->assertStringContainsString((string) $admin->email, $message);
    }

    public function testTheSamlTestCallbackWithoutAnIdpAnswerReportsTheError(): void
    {
        // the IdP posts its signed answer here; without one, the test reports the failure on the
        // edit page instead of logging anybody in
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $setting = $this->createSamlSetting();

        $this->post(admin_url("login_setting/{$setting->id}/testcallback"))
            ->assertRedirect(route('exment.login_setting.edit', ['id' => $setting->id]) . '?test_callback=1')
            ->assertSessionHas(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE);

        $message = session(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE);
        $this->assertIsString($message);
        $this->assertStringContainsString(exmtrans('common.message.error_execute'), $message);
        $this->assertStringContainsString('SAML Response not found', $message);
    }

    // the OAuth login (AuthOAuthController) ----------------------------------------

    public function testTheOAuthLoginStartsAtTheProvider(): void
    {
        $setting = $this->createOAuthSetting();
        $this->mapSsoRoutes();

        $this->assertProviderRedirect($this->get(admin_url('auth/login/google')), $setting->exment_callback_url);
        $this->assertGuest('admin');
    }

    public function testTheOAuthCallbackLogsTheUserIn(): void
    {
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->createOAuthSetting();
        $this->mapSsoRoutes();
        $this->fakeGoogleUser((string) $admin->email);

        $response = $this->get(admin_url('auth/login/google/callback'));

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('auth/login', (string) $response->headers->get('Location'));
        // the provider's user is the administrator (same email): exment logs in through an OAuth
        // login account of that user and keeps the provider's token in the session
        $oauth_user = LoginUser::query()
            ->where('base_user_id', $admin->base_user_id)
            ->where('login_type', LoginType::OAUTH)
            ->where('login_provider', 'google')
            ->firstOrFail();
        $this->assertAuthenticatedAs($oauth_user, 'admin');
        $this->assertSame('claude-coverage-token', session(Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN . '.access_token'));
    }

    // the SAML endpoints (AuthSamlController) --------------------------------------

    public function testTheSamlMetadataDescribesTheServiceProvider(): void
    {
        $setting = $this->createSamlSetting();
        $this->mapSsoRoutes();

        $response = $this->get(admin_url('saml/login/claude-cov-saml/metadata'));

        $response->assertOk();
        $this->assertStringStartsWith('text/xml', (string) $response->headers->get('Content-Type'));
        $xml = (string) $response->getContent();
        $this->assertStringContainsString('entityID="https://exment.example.com/claude-cov-saml"', $xml);
        $this->assertStringContainsString('Location="' . $setting->exment_callback_url . '"', $xml);
        $this->assertStringContainsString('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', $xml);
    }

    public function testTheSamlAcsWithoutAnIdpAnswerSendsTheGuestBackToTheLogin(): void
    {
        $this->createSamlSetting();
        $this->mapSsoRoutes();

        $this->post(admin_url('saml/login/claude-cov-saml/acs'))
            ->assertRedirect(admin_url('auth/login'))
            ->assertSessionHasErrors('sso_error');

        $this->assertGuest('admin');
    }

    private function createOAuthSetting(): LoginSetting
    {
        $setting = LoginSetting::create([
            'login_view_name' => 'Claude coverage oauth',
            'login_type' => LoginType::OAUTH,
            'active_flg' => 1,
            'options' => [
                'oauth_provider_type' => 'google',
                'oauth_client_id' => 'claude-cov-client',
                'oauth_client_secret' => 'claude-cov-secret',
                'mapping_user_column' => 'email',
            ],
        ]);
        System::clearCache();

        return $setting;
    }

    private function createSamlSetting(): LoginSetting
    {
        $setting = LoginSetting::create([
            'login_view_name' => 'Claude coverage saml',
            'login_type' => LoginType::SAML,
            'active_flg' => 1,
            'options' => [
                'saml_name' => 'claude-cov-saml',
                'saml_idp_entityid' => 'https://idp.example.com/metadata',
                'saml_idp_sso_url' => 'https://idp.example.com/sso',
                'saml_idp_x509' => self::IDP_CERTIFICATE,
                'saml_sp_entityid' => 'https://exment.example.com/claude-cov-saml',
                'saml_sp_name_id_format' => 'NAMEID_EMAIL_ADDRESS',
                'mapping_user_column' => 'email',
            ],
        ]);
        System::clearCache();

        return $setting;
    }

    /**
     * RouteServiceProvider registers the OAuth/SAML login routes at boot only when an active
     * setting exists (the test data has none): register them again as the application does.
     */
    private function mapSsoRoutes(): void
    {
        $map = new \ReflectionMethod(RouteServiceProvider::class, 'mapExmentAnonymousWebRotes');
        $map->invoke(new RouteServiceProvider($this->app));
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    /**
     * Google answers the given user: the provider object exment asks for its user() is a stub.
     */
    private function fakeGoogleUser(string $email): void
    {
        $user = (new SocialiteUser())->setToken('claude-coverage-token')->map([
            'id' => 'claude-coverage-google-id',
            'name' => 'Claude coverage',
            'email' => $email,
            'avatar' => null,
        ]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->allows(['scopes' => $provider, 'user' => $user]);
        Socialite::shouldReceive('with')->with('google')->andReturn($provider);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertProviderRedirect(TestResponse $response, string $callback_url): void
    {
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::GOOGLE_AUTH_URL, $location);
        $this->assertStringContainsString('client_id=claude-cov-client', $location);
        $this->assertStringContainsString('redirect_uri=' . urlencode($callback_url), $location);
    }
}
