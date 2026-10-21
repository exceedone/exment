<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Services\Auth2factor\Providers\Email;
use Exceedone\Exment\Services\Login\OAuth\OAuthService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Laravel 12 requires Carbon 3, whose add/sub methods refuse a numeric string
 * (Carbon 2 on v6 accepted it). env() always returns a string, so a setting
 * written in .env (EXMENT_DECAY_MINUTES, EXMENT_LOGIN_2FACTOR_VALID_PERIOD,
 * EXMENT_NOTIFY_SAVED_SKIP_MINUTES) or an OAuth "expires_in" sent as a string
 * reached Carbon as "30" and threw a TypeError (HTTP 500).
 *
 * Each test sets the value as a string, as .env does, and checks the feature
 * still works the way it does with an integer.
 */
class CarbonNumericSettingTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const USERNAME = 'carbon_numeric_setting_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        app(RateLimiter::class)->clear($this->throttleKey());
    }

    protected function tearDown(): void
    {
        app(RateLimiter::class)->clear($this->throttleKey());
        Carbon::setTestNow();
        System::clearCache();
        parent::tearDown();
    }

    /**
     * EXMENT_DECAY_MINUTES / EXMENT_MAX_ATTEMPTS from .env: a wrong password is
     * answered as usual and counted, and the lock comes after max_attempts.
     */
    public function testWrongPasswordIsCountedWhenThrottleSettingsAreStrings(): void
    {
        config(['exment.decay_minutes' => '30', 'exment.max_attempts' => '2', 'exment.throttle' => true]);
        $this->withoutMiddleware(\Exceedone\Exment\Middleware\VerifyCsrfToken::class);
        $this->withoutExceptionHandling();

        $this->postLogin()->assertStatus(302);
        $this->assertSame(1, app(RateLimiter::class)->attempts($this->throttleKey()));

        $this->postLogin()->assertStatus(302);
        $this->assertSame(2, app(RateLimiter::class)->attempts($this->throttleKey()));
        $this->assertTrue(app(RateLimiter::class)->tooManyAttempts($this->throttleKey(), 2), 'locked after max_attempts');
        // the lock length is unchanged: the same number as with an integer setting
        $this->assertLessThanOrEqual(30, app(RateLimiter::class)->availableIn($this->throttleKey()));
    }

    /**
     * EXMENT_LOGIN_2FACTOR_VALID_PERIOD from .env: the e-mail code is stored with
     * its expiry instead of the request failing.
     */
    public function testEmailVerifyCodeIsStoredWhenValidPeriodIsAString(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 10, 0, 0));
        config(['exment.login_2factor_valid_period' => '15', 'mail.default' => 'log']);
        $loginUser = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($loginUser, 'Fixture: admin login user is required.');
        $this->be($loginUser, 'admin');

        (new Email())->insertVerify();

        $row = \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $loginUser->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row, 'The verify code must be stored.');
        $this->assertSame('2026-10-20 10:15', Carbon::parse($row->valid_period_datetime)->format('Y-m-d H:i'));
    }

    /**
     * EXMENT_NOTIFY_SAVED_SKIP_MINUTES from .env: a mail sent within the window is
     * still skipped, one sent before it is not.
     */
    public function testSavedNotifySkipWindowWorksWhenItIsAString(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 10, 0, 0));
        config(['exment.notify_saved_skip_minutes' => '5']);
        $loginUser = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($loginUser, 'Fixture: admin login user is required.');
        $this->be($loginUser, 'admin');

        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        $target = $userTable->getValueModel()->query()->where('id', '<>', $loginUser->base_user_id)->first();
        $this->assertNotNull($target, 'Fixture: a second user is required.');
        $mailTemplate = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)->getValueModel()->query()->first();
        $this->assertNotNull($mailTemplate, 'Fixture: a mail template is required.');
        $notifyTarget = NotifyTarget::getModelAsUser($target);
        $this->assertNotNull($notifyTarget);

        $notify = new Notify();
        $notify->notify_trigger = NotifyTrigger::CREATE_UPDATE_DATA;
        $approval = function () use ($notify, $mailTemplate, $userTable, $target, $notifyTarget) {
            $method = new \ReflectionMethod(Notify::class, 'approvalSendUser');
            $method->setAccessible(true);
            return $method->invoke($notify, $mailTemplate, $userTable, $target, $notifyTarget, true);
        };

        // sent 2 minutes ago: inside the 5 minute window
        $log = $this->createMailSendLog($target, $mailTemplate, Carbon::now()->subMinutes(2));
        $this->assertFalse($approval(), 'a mail sent 2 minutes ago is skipped');

        // sent 10 minutes ago: outside the window
        $log->setValue('send_datetime', Carbon::now()->subMinutes(10)->format('Y-m-d H:i:s'))->save();
        $this->assertTrue($approval(), 'a mail sent 10 minutes ago is not skipped');
    }

    /**
     * OAuth for the plugin CRUD: a provider that sends "expires_in" as a string.
     */
    public function testOAuthTokenIsStoredWhenExpiresInIsAString(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 10, 0, 0));
        $loginUser = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($loginUser, 'Fixture: admin login user is required.');
        $this->be($loginUser, 'admin');

        // the raw provider response, as Socialite hands it over: "expires_in" as a string
        $socialiteUser = (new SocialiteUser())->setToken('token-carbon-test')->map(['expiresIn' => '3599']);
        $provider = \Mockery::mock();
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        \Laravel\Socialite\Facades\Socialite::shouldReceive('with')->andReturn($provider);

        $loginSetting = new LoginSetting();
        $loginSetting->id = 999999;
        $loginSetting->login_type = LoginType::OAUTH;
        $loginSetting->setOption('oauth_provider_type', 'other');
        $loginSetting->setOption('oauth_provider_name', 'carbontest');

        $token = OAuthService::callbackAccessTokenToDB($loginSetting, 'http://localhost/callback');

        $this->assertSame('token-carbon-test', $token);
        $userSetting = UserSetting::where('base_user_id', $loginUser->base_user_id)->first();
        $this->assertNotNull($userSetting, 'The user setting must be stored.');
        $expiresAt = array_get($userSetting->settings ?? [], 'plugin_crud_oauth_access_token_expires_at_999999');
        $this->assertNotNull($expiresAt, 'The token expiry must be stored.');
        $this->assertSame('2026-10-20 10:59:59', Carbon::parse($expiresAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postLogin(): \Illuminate\Testing\TestResponse
    {
        return $this->post(admin_url('auth/login'), [
            'username' => self::USERNAME,
            'password' => 'wrong-password-' . Str::random(8),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::lower(self::USERNAME) . '|127.0.0.1';
    }

    /**
     * @param mixed $target
     * @param mixed $mailTemplate
     * @return \Exceedone\Exment\Model\CustomValue
     */
    private function createMailSendLog($target, $mailTemplate, Carbon $sentAt)
    {
        $log = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG)->getValueModel();
        $log->setValue([
            'user' => $target->id,
            'mail_template' => $mailTemplate->id,
            'send_datetime' => $sentAt->format('Y-m-d H:i:s'),
        ]);
        $log->parent_id = $target->id;
        $log->parent_type = SystemTableName::USER;
        $log->save();
        return $log;
    }
}
