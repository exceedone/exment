<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Auth2factor\Providers\Email;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ThrottlesLogins without the database (through a class that uses it, the e-mail second
 * factor, which reads exment.decay_minutes as the login controllers do):
 * RateLimiter::hit() takes seconds, so the lock is exment.decay_minutes * 60 seconds;
 * the message says the minutes left.
 */
class ThrottlesLoginsTest extends UnitTestBase
{
    // nobody signs in here: the key is the address of the request
    private const KEY = '2factor|192.0.2.1';

    /**
     * @param mixed $decayMinutes
     */
    private function throttler($decayMinutes): Email
    {
        config(['exment.decay_minutes' => $decayMinutes]);
        return new Email();
    }

    private function request(): Request
    {
        return Request::create('/admin/auth-2factor/verify', 'POST', ['verify_code' => '000000'], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
    }

    /**
     * @return \Mockery\MockInterface
     */
    private function mockLimiter()
    {
        $limiter = \Mockery::mock(RateLimiter::class);
        $this->app->instance(RateLimiter::class, $limiter);
        return $limiter;
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function decayProvider(): array
    {
        return [
            'default setting 60 minutes' => [60, 3600],
            'other setting 7 minutes' => [7, 420],
            'string from .env' => ['30', 1800],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('decayProvider')]
    public function testTheLockIsTheMinutesInSeconds(mixed $decayMinutes, int $seconds): void
    {
        $limiter = $this->mockLimiter();
        $limiter->shouldReceive('hit')->once()->with(self::KEY, $seconds)->andReturn(1);

        $this->callProtectedMethod($this->throttler($decayMinutes), 'incrementLoginAttempts', $this->request());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function minutesLeftProvider(): array
    {
        return [
            'full 60 minutes' => [3600, 60],
            '61 seconds' => [61, 2],
            '60 seconds' => [60, 1],
            '1 second' => [1, 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('minutesLeftProvider')]
    public function testTheMessageSaysTheMinutesLeft(int $availableIn, int $minutes): void
    {
        $limiter = $this->mockLimiter();
        $limiter->shouldReceive('availableIn')->with(self::KEY)->andReturn($availableIn);

        $this->assertSame(exmtrans('login.throttle', $minutes), $this->callProtectedMethod($this->throttler(60), 'getLockoutMessage', $this->request()));
    }

    public function testTheThrownLockIsAsBefore(): void
    {
        $limiter = $this->mockLimiter();
        $limiter->shouldReceive('availableIn')->andReturn(3600);

        try {
            $this->callProtectedMethod($this->throttler(60), 'sendLockoutResponse', $this->request());
            $this->fail('ValidationException is expected');
        } catch (ValidationException $ex) {
            $this->assertSame(429, $ex->status);
            $this->assertSame(['username' => [exmtrans('login.throttle', 60)]], $ex->errors());
        }
    }

    public function testTheSecondFactorLockGoesBackWithTheMessage(): void
    {
        $limiter = $this->mockLimiter();
        $limiter->shouldReceive('availableIn')->andReturn(420);

        $response = $this->callProtectedMethod($this->throttler(7), 'redirectLockoutResponse', $this->request(), 'verify_code');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $errors = $response->getSession()?->get('errors');
        $this->assertNotNull($errors);
        $this->assertSame(exmtrans('login.throttle', 7), $errors->first('verify_code'));
    }
}
