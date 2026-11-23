<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Services\UserTimezone;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The language of each user on the pages (ISS02835): the login page, the user menu, the user settings.
 */
class UserLocaleTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** @var mixed */
    protected $localeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->localeBefore = config('exment.locale');
        // (the language of the browser is not used in the tests unless asked)
        config(['exment.locale' => 'ja', 'exment.locale_from_browser' => true]);
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        config(['exment.locale' => $this->localeBefore, 'exment.locale_from_browser' => null]);
        UserTimezone::setCurrent(null);
        parent::tearDown();
    }

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }

    public function testLoginPageFollowsTheBrowserAndTheChoice(): void
    {
        $this->get(admin_url('auth/login'), ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertOk()
            ->assertSee(trans('admin.login', [], 'en'))
            ->assertSee('exment-login-locale', false);

        $this->get(admin_url('auth/login'), ['Accept-Language' => 'ja'])
            ->assertOk()
            ->assertSee(trans('admin.login', [], 'ja'));

        // the choice of the login page: a cookie
        $this->post(admin_url('auth/login_locale'), ['locale' => 'en'])
            ->assertRedirect(admin_url('auth/login'))
            ->assertCookie(UserLocale::COOKIE_NAME, 'en');
        // a language that is not installed is not kept
        $this->post(admin_url('auth/login_locale'), ['locale' => 'xx'])
            ->assertCookieExpired(UserLocale::COOKIE_NAME);
    }

    public function testUserMenuSavesTheLanguageOfTheUser(): void
    {
        $user = $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);

        $this->get(admin_url(), ['Accept-Language' => 'ja'])->assertOk()->assertSee('exment-user-locale', false);

        $this->post(admin_url('auth/setting/locale'), ['locale' => 'en'])->assertRedirect();
        UserLocale::clearCache();
        /** @var LoginUser $saved */
        $saved = LoginUser::findOrFail($user->id);
        $this->assertSame('en', $saved->getSettingValue(UserLocale::SETTING_KEY));

        // the next page in English, whatever the browser says
        UserLocale::clearCache();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/setting'), ['Accept-Language' => 'ja'])
            ->assertOk()
            ->assertSee(trans('exment::exment.user.timezone', [], 'en'));
    }
}
