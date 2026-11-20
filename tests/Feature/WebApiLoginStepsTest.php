<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The web guard is logged in as soon as the password is right. The web pages then wait for the
 * second factor (Authenticate2factor) and for a required password change
 * (AuthenticatePasswordLimit). The web API (admin/webapi/...) is called with the same session,
 * so it has to wait for the same steps.
 */
class WebApiLoginStepsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->be($user);
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function use2factor(): void
    {
        config(['exment.login_use_2factor' => true]);
        System::login_use_2factor(true);
        System::clearCache();
    }

    protected function webapiData()
    {
        System::clearRequestSession();
        return $this->get(admin_urls('webapi', 'data', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL), static::AJAX);
    }

    public function testTheWebApiAnswersAfterTheLoginWithout2factor(): void
    {
        $this->webapiData()->assertStatus(200);
    }

    public function testTheWebApiWaitsForTheSecondFactor(): void
    {
        $this->use2factor();
        $this->webapiData()->assertStatus(401);
    }

    public function testTheWebApiAnswersAfterTheSecondFactor(): void
    {
        $this->use2factor();
        $this->withSession([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
        $this->webapiData()->assertStatus(200);
    }

    public function testTheWebApiWaitsForTheRequiredPasswordChange(): void
    {
        $this->withSession([Define::SYSTEM_KEY_SESSION_FIRST_CHANGE_PASSWORD => true]);
        $this->webapiData()->assertStatus(401);
    }

    public function testTheWebApiWaitsForTheExpiredPasswordChange(): void
    {
        $this->withSession([Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT => true]);
        $this->webapiData()->assertStatus(401);
    }
}
