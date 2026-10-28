<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;
use PragmaRX\Google2FA\Google2FA;

/**
 * Auth2factorController::verify/sendmail/register. The controller has no methods of its own:
 * __call() delegates to the active provider (Auth2factorService::getProvider()). Email is the
 * default provider; sendmail/register exist only on the Google provider, so those tests switch
 * System::login_2factor_provider to google first.
 */
class Auth2factorGoogleRouteTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const REGISTER_CODE = 'claude-coverage-register-code';

    /** @var LoginUser */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // Wrong codes count as failed login attempts; without this they could lock out later tests.
        config(['exment.throttle' => false]);
        $this->admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($this->admin);
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    private function useGoogleProvider(): void
    {
        System::login_2factor_provider(Login2FactorProviderType::GOOGLE);
        System::clearCache();
    }

    private function storeRegisterCode(): void
    {
        \DB::table(SystemTableName::EMAIL_CODE_VERIFY)->insert([
            'login_user_id' => $this->admin->id,
            'email' => $this->admin->email,
            'verify_code' => self::REGISTER_CODE,
            'verify_type' => '2factor_google',
            'valid_period_datetime' => Carbon::now()->addMinutes(60)->format('Y/m/d H:i'),
        ]);
    }

    private function giveAdminASecretKey(): string
    {
        $key = (new Google2FA())->generateSecretKey();
        $this->admin->auth2fa_key = encrypt($key);
        $this->admin->auth2fa_available = false;
        $this->admin->save();

        return $key;
    }

    public function testEmailVerifyWithWrongCodeFailsAndDoesNotMark2FactorDone(): void
    {
        $this->post(admin_url('auth-2factor/verify'), [
            'verify_code' => 'not-the-right-code',
        ])->assertRedirect()->assertSessionHasErrors('verify_code');

        $this->assertNull(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR));
    }

    public function testGoogleSendmailStoresARegisterCodeAndSendsOneMail(): void
    {
        $this->useGoogleProvider();
        Notification::fake();

        $this->get(admin_url('auth-2factor/google/sendmail'))->assertStatus(200);

        $this->assertDatabaseHas(SystemTableName::EMAIL_CODE_VERIFY, [
            'login_user_id' => $this->admin->id,
            'verify_type' => '2factor_google',
        ]);
        Notification::assertCount(1);
    }

    public function testGoogleRegisterWithAWrongCodeRedirectsBackWithAnError(): void
    {
        $this->useGoogleProvider();
        $before = $this->admin->fresh()->auth2fa_key;

        $this->get(admin_url('auth-2factor/google/register?code=not-a-stored-code'))
            ->assertRedirect();

        // register() flags "code" with an empty message, which MessageBag::has() and so
        // assertSessionHasErrors() do not count; the key is in the error bag all the same.
        $errors = session('errors');
        $this->assertInstanceOf(ViewErrorBag::class, $errors);
        $this->assertContains('code', $errors->getBag('default')->keys());
        $this->assertSame($before, $this->admin->fresh()->auth2fa_key);
    }

    public function testGoogleRegisterWithAStoredCodeCreatesTheSecretKey(): void
    {
        $this->useGoogleProvider();
        $this->admin->auth2fa_key = null;
        $this->admin->save();
        $this->storeRegisterCode();

        $this->get(admin_url('auth-2factor/google/register?code=' . self::REGISTER_CODE))
            ->assertStatus(200);

        $stored = $this->admin->fresh()->auth2fa_key;
        $this->assertNotNull($stored);
        $this->assertNotSame('', decrypt($stored));
    }

    public function testGoogleVerifyWithAWrongOtpFails(): void
    {
        $this->useGoogleProvider();
        $this->giveAdminASecretKey();

        $this->post(admin_url('auth-2factor/verify'), ['verify_code' => 'not-a-code'])
            ->assertRedirect()
            ->assertSessionHasErrors('verify_code');

        $this->assertNull(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR));
        $this->assertFalse(boolval($this->admin->fresh()->auth2fa_available));
    }

    public function testGoogleVerifyWithTheCurrentOtpCompletesTheSecondFactor(): void
    {
        $this->useGoogleProvider();
        $key = $this->giveAdminASecretKey();

        $this->post(admin_url('auth-2factor/verify'), ['verify_code' => (new Google2FA())->getCurrentOtp($key)])
            ->assertRedirect(admin_url(''))
            ->assertSessionHas(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR, true);

        $this->assertTrue(boolval($this->admin->fresh()->auth2fa_available));
    }
}
