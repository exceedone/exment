<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\IpAddress;
use Exceedone\Exment\Services\MaintenanceMode;
use Exceedone\Exment\Services\UserApiKeys;

/**
 * IPv6 in the IP restrictions (ISS02909): the IP filters of the system setting, the allowed IPs
 * of an API key and of the maintenance mode all read and compare their rules with IpAddress.
 */
class IpAddressTest extends UnitTestBase
{
    public function testRulesAreSplitByLineAndComma(): void
    {
        $this->assertSame(
            ['192.0.2.1', '2001:db8::/32', '198.51.100.0/24', '::1'],
            IpAddress::splitRules(" 192.0.2.1\r\n\r\n2001:db8::/32\n198.51.100.0/24, ::1 ,")
        );
        $this->assertSame([], IpAddress::splitRules(null));
        $this->assertSame([], IpAddress::splitRules(" \r\n "));
    }

    public function testValidRules(): void
    {
        foreach (['192.0.2.1', '192.0.2.0/24', '0.0.0.0/0', '2001:db8::1', '2001:DB8::/32', '2001:db8:0:0:0:0:0:1/128',
            '::1', '::/0', 'fe80::1/10', '::ffff:192.0.2.1', '::ffff:192.0.2.0/120'] as $rule) {
            $this->assertTrue(IpAddress::isValidRule($rule), $rule);
        }
        foreach (['', 'example.com', '192.0.2.256', '192.0.2.0/33', '192.0.2.0/', '192.0.2.0/x', '2001:db8::/129',
            '2001:db8:::1', '2001:db8::g', '2001:db8::/-1', '[2001:db8::1]'] as $rule) {
            $this->assertFalse(IpAddress::isValidRule($rule), $rule);
        }
        // "/0" only where every address may be allowed
        $this->assertFalse(IpAddress::isValidRule('::/0', false));
        $this->assertFalse(IpAddress::isValidRule('0.0.0.0/0', false));
        $this->assertTrue(IpAddress::isValidRule('2001:db8::/32', false));

        $this->assertSame(['bad', '2001:db8::/200'], IpAddress::invalidRules("192.0.2.1\nbad\n2001:db8::/200\n2001:db8::/32"));
    }

    public function testIpv6AddressesAndRanges(): void
    {
        $rules = ['2001:db8::/32', '2001:db8:ffff::5'];
        $this->assertTrue(IpAddress::matches('2001:db8::1', $rules));
        $this->assertTrue(IpAddress::matches('2001:db8:1234:5678::abcd', $rules));
        $this->assertTrue(IpAddress::matches('2001:DB8:0:0::1', $rules), 'case and the long form do not matter');
        $this->assertFalse(IpAddress::matches('2001:db9::1', $rules));

        $this->assertTrue(IpAddress::matches('2001:db9::5', ['2001:0db9:0000::5']), 'a single address in its long form');
        $this->assertFalse(IpAddress::matches('2001:db9::6', ['2001:db9::5']));

        // a /64: the addresses a home or an office gets
        $this->assertTrue(IpAddress::matches('2001:db8:aa:bb:1:2:3:4', '2001:db8:aa:bb::/64'));
        $this->assertFalse(IpAddress::matches('2001:db8:aa:bc::1', '2001:db8:aa:bb::/64'));

        // IPv4 and IPv6 rules together
        $this->assertTrue(IpAddress::matches('192.0.2.7', "2001:db8::/32\n192.0.2.0/24"));
        $this->assertTrue(IpAddress::matches('::1', "127.0.0.1\n::1"));
        $this->assertFalse(IpAddress::matches('2001:db8::1', '192.0.2.0/24'));
    }

    public function testIpv4MappedIpv6IsTheIpv4Address(): void
    {
        // a dual-stack server sees an IPv4 client as ::ffff:192.0.2.7
        $this->assertSame('192.0.2.7', IpAddress::normalize('::ffff:192.0.2.7'));
        $this->assertSame('192.0.2.7', IpAddress::normalize('::FFFF:c000:0207'));
        $this->assertSame('2001:db8::1', IpAddress::normalize('[2001:DB8:0::1]'));
        $this->assertTrue(IpAddress::matches('::ffff:192.0.2.7', ['192.0.2.0/24']));
        $this->assertTrue(IpAddress::matches('::ffff:192.0.2.7', ['192.0.2.7']));
        $this->assertFalse(IpAddress::matches('::ffff:198.51.100.7', ['192.0.2.0/24']));

        // and a rule written that way is the IPv4 rule
        $this->assertSame('192.0.2.0/24', IpAddress::normalizeRule('::ffff:192.0.2.0/120'));
        $this->assertTrue(IpAddress::matches('192.0.2.7', ['::ffff:192.0.2.0/120']));
        $this->assertTrue(IpAddress::matches('192.0.2.7', ['::ffff:192.0.2.7']));
        $this->assertFalse(IpAddress::matches('192.0.3.7', ['::ffff:192.0.2.0/120']));
    }

    public function testNoAddressOrNoRuleMatchesNothing(): void
    {
        $this->assertFalse(IpAddress::matches(null, ['2001:db8::/32']));
        $this->assertFalse(IpAddress::matches('', ['2001:db8::/32']));
        $this->assertFalse(IpAddress::matches('2001:db8::1', []));
        $this->assertFalse(IpAddress::matches('not-an-ip', ['2001:db8::/32']));
    }

    public function testTheApiKeyAndTheMaintenanceModeTakeIpv6(): void
    {
        $this->assertSame(['2001:db8::/32', '192.0.2.1', '::1'], UserApiKeys::parseIps("2001:db8::/32\n192.0.2.1, ::1"));
        try {
            UserApiKeys::parseIps("2001:db8::/32\n2001:db8::/129");
            $this->fail('a prefix longer than 128 is refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('allowed_ips', $e->getMessage());
        }

        $this->assertTrue(MaintenanceMode::validIp('2001:db8::/32'));
        $this->assertTrue(MaintenanceMode::validIp('2001:db8::1'));
        $this->assertFalse(MaintenanceMode::validIp('2001:db8::/129'));
        $this->assertFalse(MaintenanceMode::validIp('::/0'));
    }
}
