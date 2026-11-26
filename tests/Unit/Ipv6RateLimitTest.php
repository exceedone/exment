<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\AuthController;
use Exceedone\Exment\Middleware\ThrottleRequests;
use Exceedone\Exment\Services\IpAddress;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rate limits and the login lockouts count an IPv6 address as its /64 (ISS02909): one IPv6
 * client can take a new address in its network for every try.
 */
class Ipv6RateLimitTest extends UnitTestBase
{
    /**
     * @param array<string, mixed> $input
     */
    protected function request(string $ip, array $input = []): Request
    {
        $request = Request::create('/admin/auth/login', 'POST', $input, [], [], ['REMOTE_ADDR' => $ip]);
        $request->setRouteResolver(function () use ($request) {
            return (new Route('POST', 'admin/auth/login', []))->bind($request);
        });
        return $request;
    }

    public function testAnIpv6AddressCountsAsItsSlash64(): void
    {
        $this->assertSame('2001:db8:1:2::/64', IpAddress::limiterKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame('2001:db8:1:2::/64', IpAddress::limiterKey('2001:DB8:1:2::1'));
        $this->assertSame('2001:db8:1:3::/64', IpAddress::limiterKey('2001:db8:1:3::1'));
        $this->assertSame('::/64', IpAddress::limiterKey('::1'));

        // IPv4 (an IPv4-mapped one too) counts as itself
        $this->assertSame('192.0.2.7', IpAddress::limiterKey('192.0.2.7'));
        $this->assertSame('192.0.2.7', IpAddress::limiterKey('::ffff:192.0.2.7'));
        $this->assertSame('', IpAddress::limiterKey(null));
    }

    public function testThePrefixIsAConfig(): void
    {
        config(['exment.ipv6_limit_prefix' => 48]);
        $this->assertSame('2001:db8:1::/48', IpAddress::limiterKey('2001:db8:1:2::1'));
        config(['exment.ipv6_limit_prefix' => 56]);
        $this->assertSame('2001:db8:1:200::/56', IpAddress::limiterKey('2001:db8:1:2ff::1'));
        config(['exment.ipv6_limit_prefix' => 128]);
        $this->assertSame('2001:db8:1:2::1', IpAddress::limiterKey('2001:db8:1:2::1'));
        config(['exment.ipv6_limit_prefix' => 0]);
        $this->assertSame('2001:db8:1:2::/64', IpAddress::limiterKey('2001:db8:1:2::1'), 'a wrong value is 64');
        config(['exment.ipv6_limit_prefix' => 64]);
    }

    public function testTheLoginLockoutCountsTheSlash64(): void
    {
        $controller = (new \ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AuthController::class, 'throttleKey');
        $method->setAccessible(true);
        $username = (new \ReflectionMethod(AuthController::class, 'username'));
        $username->setAccessible(true);
        $field = strval($username->invoke($controller));

        $a = $method->invoke($controller, $this->request('2001:db8:1:2::a', [$field => 'Admin']));
        $b = $method->invoke($controller, $this->request('2001:db8:1:2:ffff::b', [$field => 'admin']));
        $c = $method->invoke($controller, $this->request('2001:db8:1:3::a', [$field => 'admin']));
        $this->assertSame('admin|2001:db8:1:2::/64', $a);
        $this->assertSame($a, $b, 'another address of the same /64 is the same count');
        $this->assertNotSame($a, $c);

        $this->assertSame('admin|192.0.2.7', $method->invoke($controller, $this->request('192.0.2.7', [$field => 'admin'])));
    }

    public function testTheRouteThrottleCountsTheSlash64(): void
    {
        $middleware = app(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->assertInstanceOf(ThrottleRequests::class, $middleware, '"throttle" is the IPv6-aware one');

        $method = new \ReflectionMethod(ThrottleRequests::class, 'resolveRequestSignature');
        $method->setAccessible(true);
        $a = $method->invoke($middleware, $this->request('2001:db8:1:2::a'));
        $b = $method->invoke($middleware, $this->request('2001:db8:1:2:ffff::b'));
        $c = $method->invoke($middleware, $this->request('2001:db8:1:3::a'));
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame(
            $method->invoke($middleware, $this->request('192.0.2.7')),
            $method->invoke($middleware, $this->request('192.0.2.8')),
            'IPv4 addresses are counted one by one as before'
        );
    }

    public function testTheApiLimiterCountsTheSlash64(): void
    {
        $limiter = RateLimiter::limiter('api');
        $this->assertNotNull($limiter);

        $keys = [];
        foreach (['2001:db8:1:2::a', '2001:db8:1:2:ffff::b', '2001:db8:1:3::a'] as $ip) {
            $limit = $limiter($this->request($ip));
            $limit = is_array($limit) ? $limit[0] : $limit;
            $keys[] = $limit->key;
        }
        $this->assertSame('2001:db8:1:2::/64', $keys[0]);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[0], $keys[2]);
    }
}
