<?php

namespace Exceedone\Exment\Services\Login;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * List of IP addresses or CIDR entered on a setting form, 1 per line. (Ex. "192.168.0.0/16", "2001:db8::/32")
 * Used by the login restriction by country and the IP block.
 */
class IpAddressList
{
    /**
     * Parse the text. 1 per line, blank lines are ignored.
     *
     * @param mixed $text
     * @return array<string>
     */
    public static function parse($text): array
    {
        if (!is_string($text) || $text === '') {
            return [];
        }

        $ips = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $ips[] = $line;
            }
        }
        return $ips;
    }

    /**
     * Get the lines which are not an IP address nor CIDR.
     *
     * @param mixed $text
     * @return array<string>
     */
    public static function getInvalid($text): array
    {
        return array_values(array_filter(static::parse($text), function ($line) {
            return !static::isValidIpOrCidr($line);
        }));
    }

    /**
     * @param string $value
     * @return bool
     */
    public static function isValidIpOrCidr(string $value): bool
    {
        $parts = explode('/', $value, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) == 1) {
            return true;
        }

        $max = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
        return ctype_digit($parts[1]) && intval($parts[1]) <= $max;
    }

    /**
     * Valid IP address. IPv4-mapped IPv6 address (::ffff:192.0.2.1) is converted to IPv4.
     *
     * @param string|null $ip
     * @return string|null null if not an IP address
     */
    public static function normalize(?string $ip): ?string
    {
        if (is_nullorempty($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        // a valid IP address: always packed
        $packed = (string)inet_pton($ip);
        if (strlen($packed) == 16 && substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            return (string)inet_ntop(substr($packed, 12));
        }
        return $ip;
    }

    /**
     * Whether the IP address is in the list.
     *
     * @param string|null $ip
     * @param array<string> $list IP addresses or CIDR
     * @return bool
     */
    public static function contains(?string $ip, array $list): bool
    {
        $ip = static::normalize($ip);
        if (is_null($ip) || count($list) == 0) {
            return false;
        }
        return IpUtils::checkIp($ip, $list);
    }
}
