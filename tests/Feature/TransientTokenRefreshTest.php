<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Laravel\Passport\Passport;

/**
 * GET admin/oauth/token/refresh (Passport TransientTokenController::refresh) gives the logged-in
 * user a fresh API token cookie. It runs on the session and login middleware of the web OAuth
 * routes; on the anonymous API options it had no session, and every request answered 500.
 */
class TransientTokenRefreshTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testAGuestIsSentToTheLoginScreen(): void
    {
        $this->get(admin_url('oauth/token/refresh'))->assertRedirect(admin_url('auth/login'));
    }

    public function testALoggedInUserGetsAFreshApiTokenCookie(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->get(admin_url('oauth/token/refresh'))
            ->assertOk()
            ->assertSeeText('Refreshed.')
            ->assertCookie(Passport::$cookie, null, false);
    }
}
