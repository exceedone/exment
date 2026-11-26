<?php

namespace Exceedone\Exment\Services;

/**
 * Which webhook URLs Exment posts to (Slack, Microsoft Teams).
 *
 * A notify is set by a table manager, and Exment posts to its URL from the
 * server. So the URL may not point at the server itself (loopback) or at the
 * link-local range, where cloud servers keep their metadata and credentials.
 * Private networks (10/8, 172.16/12, 192.168/16, fc00::/7) are allowed, since an
 * on-premise Exment may post to a chat server in the same network, unless
 * EXMENT_NOTIFY_WEBHOOK_DENY_PRIVATE is set (as on a shared service).
 */
class WebhookUrlGuard
{
    /**
     * Whether Exment may post to the URL.
     */
    public static function isAllowed(?string $url, ?bool $denyPrivate = null): bool
    {
        return static::allowedIps($url, $denyPrivate) !== null;
    }

    /**
     * The addresses of the host Exment may post to, to connect to these and no other (a name
     * may answer another address the next time it is asked). Null: not allowed; empty: a host
     * the administrator allows, connected to by its name.
     *
     * @return array<string>|null
     */
    public static function allowedIps(?string $url, ?bool $denyPrivate = null): ?array
    {
        if (is_nullorempty($url)) {
            return null;
        }
        $parts = parse_url((string)$url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = trim($parts['host'], '[]');

        // hosts the administrator allows (EXMENT_NOTIFY_WEBHOOK_ALLOW_HOSTS,
        // comma separated), e.g. a chat server on this server itself
        $allowHosts = array_filter(array_map(function ($h) {
            return strtolower(trim($h));
        }, explode(',', (string)config('exment.notify_webhook_allow_hosts', ''))));
        if (in_array(strtolower($host), $allowHosts, true)) {
            return [];
        }

        $ips = static::resolve($host);
        if (empty($ips)) {
            return null;
        }

        $denyPrivate = $denyPrivate ?? boolval(config('exment.notify_webhook_deny_private', false));
        foreach ($ips as $ip) {
            if (!static::isAllowedIp($ip, $denyPrivate)) {
                return null;
            }
        }
        return $ips;
    }

    /**
     * @return array<string>
     */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            foreach ($records ?: [] as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if (empty($ips)) {
            $ips = @gethostbynamel($host) ?: [];
        }
        return array_values(array_unique($ips));
    }

    protected static function isAllowedIp(string $ip, bool $denyPrivate): bool
    {
        // IPv4 written inside IPv6 (::ffff:127.0.0.1) is checked as IPv4
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }

        // loopback, link-local (cloud metadata), unspecified and other reserved ranges
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // fd00:ec2::/32: AWS instance metadata over IPv6; 168.63.129.16: Azure host
        // multicast (224.0.0.0/4, ff00::/8) is never a server either
        foreach (['0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '127.0.0.0/8', '224.0.0.0/4', 'fe80::/10', '::1/128', 'ff00::/8', 'fd00:ec2::/32', '168.63.129.16/32'] as $cidr) {
            if (static::inCidr($ip, $cidr)) {
                return false;
            }
        }
        if ($denyPrivate && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            return false;
        }
        return true;
    }

    protected static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) != strlen($subnetBin)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rest)) & 0xff);
        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }
}
