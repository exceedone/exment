<?php

namespace Exceedone\Exment\Services;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The IP addresses and ranges of the IP restrictions (ISS02909): the IP filters of the system
 * setting, the allowed IPs of an API key and of the maintenance mode.
 *
 * - IPv4 and IPv6, a single address or a range (CIDR: 192.168.0.0/24, 2001:db8::/32)
 * - an IPv4-mapped IPv6 address (::ffff:192.0.2.1, what a dual-stack server can see) is the
 *   IPv4 address it carries, both as the client address and in a rule
 * - one rule per line, or several separated by commas
 */
class IpAddress
{
    /**
     * The rules of a text: one per line or comma separated, trimmed, empty ones dropped.
     *
     * @return array<int, string>
     */
    public static function splitRules(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$text) ?: []), function ($rule) {
            return $rule !== '';
        }));
    }

    /**
     * An IP address or a range (CIDR).
     *
     * @param bool $allowAll true: "/0" (every address) is a valid range
     */
    public static function isValidRule(string $rule, bool $allowAll = true): bool
    {
        $parts = explode('/', trim($rule), 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;
        if (!ctype_digit($parts[1]) || intval($parts[1]) > $max) {
            return false;
        }
        return $allowAll || intval($parts[1]) >= 1;
    }

    /**
     * The rules of a text that are not an IP address or a range.
     *
     * @return array<int, string>
     */
    public static function invalidRules(?string $text, bool $allowAll = true): array
    {
        return array_values(array_filter(static::splitRules($text), function ($rule) use ($allowAll) {
            return !static::isValidRule($rule, $allowAll);
        }));
    }

    /**
     * The address as it is compared: an IPv4-mapped IPv6 address is the IPv4 address, an IPv6
     * address is in its short lower-case form (2001:DB8:0::1 → 2001:db8::1).
     */
    public static function normalize(string $ip): string
    {
        $ip = trim($ip);
        // [2001:db8::1] as some proxies write it
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return strval(inet_ntop(substr($packed, 12)));
        }
        return strval(inet_ntop($packed));
    }

    /**
     * A rule as it is compared: an IPv4-mapped IPv6 rule is the IPv4 rule
     * (::ffff:192.0.2.0/120 → 192.0.2.0/24).
     */
    public static function normalizeRule(string $rule): string
    {
        $parts = explode('/', trim($rule), 2);
        $address = static::normalize($parts[0]);
        if (count($parts) === 1) {
            return $address;
        }
        $mask = $parts[1];
        $mapped = $address !== $parts[0] && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if ($mapped && ctype_digit($mask)) {
            // a mapped range narrower than the IPv4 part covers no IPv4 address in full
            $mask = strval(max(0, intval($mask) - 96));
        }
        return $address . '/' . $mask;
    }

    /**
     * The address as the rate limits and the lockouts count it (ISS02909).
     *
     * One IPv6 client has a whole network (a /64 for a home or an office) and can take a new
     * address in it for every try, so an IPv6 address counts as its network:
     * 2001:db8:1:2:aaaa::1 → 2001:db8:1:2::/64. An IPv4 address (an IPv4-mapped one too) counts
     * as itself. The prefix is config exment.ipv6_limit_prefix (64; 128 counts each address).
     */
    public static function limiterKey(?string $ip): string
    {
        $ip = static::normalize(strval($ip));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $prefix = intval(config('exment.ipv6_limit_prefix', 64));
        $prefix = $prefix >= 1 && $prefix <= 128 ? $prefix : 64;
        if ($prefix === 128) {
            return $ip;
        }
        return static::network($ip, $prefix) . '/' . $prefix;
    }

    /**
     * The network of an IPv6 address: the address with the bits after the prefix cleared.
     */
    public static function network(string $ipv6, int $prefix): string
    {
        $packed = @inet_pton($ipv6);
        if ($packed === false || strlen($packed) !== 16) {
            return $ipv6;
        }
        $mask = str_repeat("\xff", intdiv($prefix, 8));
        if ($prefix % 8 !== 0) {
            $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
        }
        $mask = str_pad($mask, 16, "\0");
        return strval(inet_ntop($packed & $mask));
    }

    /**
     * Whether the address is in one of the rules.
     *
     * @param array<int, string>|string $rules the rules, or a text of rules
     */
    public static function matches(?string $ip, $rules): bool
    {
        if (!is_string($ip) || $ip === '') {
            return false;
        }
        $rules = is_array($rules) ? $rules : static::splitRules($rules);
        $rules = array_values(array_filter(array_map(function ($rule) {
            return static::normalizeRule(strval($rule));
        }, $rules)));
        if (count($rules) === 0) {
            return false;
        }
        return IpUtils::checkIp(static::normalize($ip), $rules);
    }
}
