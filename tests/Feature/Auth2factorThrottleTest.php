<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Auth2factor\Providers\Email;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * The second factor (a code sent by email, or the code of an authenticator app) is short, so
 * the wrong tries are limited. The limit is counted for the user who passed the password, and
 * a value the client sends can not start a new count.
 */
class Auth2factorThrottleTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var LoginUser */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
        Mail::fake();
        config(['exment.throttle' => true, 'exment.max_attempts' => 3, 'exment.decay_minutes' => 60]);
        config(['exment.login_use_2factor' => true]);
        System::login_use_2factor(true);
        System::clearCache();

        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->user = $user;
        $this->be($user);
        app(RateLimiter::class)->clear('2factor|' . $user->id);
    }

    protected function tearDown(): void
    {
        app(RateLimiter::class)->clear('2factor|' . $this->user->id);
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function tryCode(string $code, array $more = [])
    {
        System::clearRequestSession();
        return $this->post(admin_url('auth-2factor/verify'), array_merge(['verify_code' => $code], $more));
    }

    protected function lastError(): ?string
    {
        $errors = session('errors');
        return $errors ? $errors->first('verify_code') : null;
    }

    public function testTheLimitIsNotResetByAValueTheClientSends(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->tryCode('000000', ['username' => 'try' . $i]);
            $this->assertSame(exmtrans('2factor.message.verify_failed'), $this->lastError());
        }

        // after the limit even the right code is not taken, whatever else the client sends
        \DB::table(SystemTableName::EMAIL_CODE_VERIFY)->insert([
            'login_user_id' => $this->user->id,
            'email' => $this->user->email,
            'verify_code' => '123456',
            'verify_type' => '2factor_email',
            'valid_period_datetime' => Carbon::now()->addMinutes(10)->format('Y/m/d H:i'),
        ]);
        $this->tryCode('123456', ['username' => 'try-more']);
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    public function testTheRightCodeIsTakenBeforeTheLimit(): void
    {
        \DB::table(SystemTableName::EMAIL_CODE_VERIFY)->insert([
            'login_user_id' => $this->user->id,
            'email' => $this->user->email,
            'verify_code' => '123456',
            'verify_type' => '2factor_email',
            'valid_period_datetime' => Carbon::now()->addMinutes(10)->format('Y/m/d H:i'),
        ]);
        $this->tryCode('000000');
        $this->tryCode('123456')->assertRedirect(admin_url(''));
        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    public function testOnlyTheNewestEmailCodeIsKept(): void
    {
        $email = new Email();
        $email->insertVerify();
        $email->insertVerify();

        $count = \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $this->user->id)
            ->where('verify_type', '2factor_email')
            ->count();
        $this->assertSame(1, $count);
    }
}
