<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\ApiIPFilter;
use Exceedone\Exment\Middleware\WebIPFilter;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;

/**
 * The IP filters of the system setting with IPv6 clients (ISS02909).
 */
class Ipv6IpFilterTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * @param class-string<WebIPFilter|ApiIPFilter> $middleware
     */
    protected function passes(string $middleware, string $ip): bool
    {
        System::clearRequestSession();
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        $result = (new $middleware())->handle($request, function () {
            return 'passed';
        });
        return $result === 'passed';
    }

    public function testTheWebFilterTakesIpv6(): void
    {
        System::web_ip_filters("192.0.2.0/24\r\n2001:db8::/32\r\n2001:db9::5");

        $this->assertTrue($this->passes(WebIPFilter::class, '2001:db8:1:2::3'));
        $this->assertTrue($this->passes(WebIPFilter::class, '2001:db9::5'));
        $this->assertTrue($this->passes(WebIPFilter::class, '192.0.2.9'));
        $this->assertTrue($this->passes(WebIPFilter::class, '::ffff:192.0.2.9'), 'an IPv4 client seen by a dual-stack server');
        $this->assertFalse($this->passes(WebIPFilter::class, '2001:db9::6'));
        $this->assertFalse($this->passes(WebIPFilter::class, '::ffff:198.51.100.1'));
    }

    public function testTheApiFilterTakesIpv6AndLinesWithoutCarriageReturns(): void
    {
        // a setting written by a script can have "\n" only
        System::api_ip_filters("2001:db8:aa:bb::/64\n198.51.100.1");

        $this->assertTrue($this->passes(ApiIPFilter::class, '2001:db8:aa:bb:ffff::1'));
        $this->assertTrue($this->passes(ApiIPFilter::class, '198.51.100.1'));
        $this->assertFalse($this->passes(ApiIPFilter::class, '2001:db8:aa:bc::1'));
    }

    /**
     * The advanced system setting as the page posts it, with the changes.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    protected function advancedSystemInput(array $changes): array
    {
        $input = [];
        foreach (System::get_system_values(['advanced', 'notify']) as $key => $value) {
            $input[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }
        $input['advanced'] = '1';
        $input['filter_search_type'] = $input['filter_search_type'] ?? 'forward';

        return array_merge($input, $changes);
    }

    public function testTheSystemSettingSavesIpv6AndRefusesAWrongLine(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        // the API filter only: the web filter would refuse the next requests of this test
        $this->post(admin_url('system'), $this->advancedSystemInput([
            'web_ip_filters' => '',
            'api_ip_filters' => "2001:db8::/32\r\n::ffff:192.0.2.0/120\r\n192.0.2.1",
        ]))->assertRedirect(admin_url('system') . '?advanced=1')->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertSame("2001:db8::/32\r\n::ffff:192.0.2.0/120\r\n192.0.2.1", System::api_ip_filters());

        System::clearRequestSession();
        $this->post(admin_url('system'), $this->advancedSystemInput([
            'web_ip_filters' => "2001:db8::/32\r\n2001:db8::/200",
            'api_ip_filters' => '',
        ]))->assertSessionHasErrors('web_ip_filters');
        System::clearCache();
        $this->assertEmpty(System::web_ip_filters(), 'nothing saved');
        $this->assertSame("2001:db8::/32\r\n::ffff:192.0.2.0/120\r\n192.0.2.1", System::api_ip_filters(), 'nothing saved');
    }
}
