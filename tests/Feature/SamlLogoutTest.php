<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Providers\RouteServiceProvider;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * GET admin/saml/logout (AuthSamlController::sls, the SAML single logout) exists only while a SAML
 * login setting is active, so the test creates one and maps the anonymous web routes again, as the
 * application does at boot.
 */
class SamlLogoutTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        LoginSetting::create([
            'login_view_name' => 'saml logout test',
            'login_type' => LoginType::SAML,
            'active_flg' => 1,
            'options' => ['saml_name' => 'saml-logout-test'],
        ]);
        // the boot-time read of the login settings is cached for the request
        System::clearCache();

        $map = new \ReflectionMethod(RouteServiceProvider::class, 'mapExmentAnonymousWebRotes');
        $map->invoke(new RouteServiceProvider($this->app));
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    public function testTheLogoutEndsTheSessionAndOpensTheLoginPage(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->get(admin_url('saml/logout'))->assertRedirect(admin_url('auth/login'));

        $this->assertGuest('admin');
    }
}
