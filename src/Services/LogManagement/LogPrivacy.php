<?php

namespace Exceedone\Exment\Services\LogManagement;

use Exceedone\Exment\Model\System;

/**
 * What the logs keep of personal data (ISS02839, part 4). Logs can be stolen: by default they
 * keep as little as the logs need.
 *
 * - log_store_input (off by default): the operation log (the pages, the API, MCP) keeps the keys
 *   of what was sent, its values as "***". On: the values as before, with the wider mask of
 *   maskSecrets() too. While it is off, the application log masks mail addresses and the quoted
 *   values of SQL in its messages (LogPrivacyTap)
 * - log_ip_mode: keep (default: the audits need it) / truncate (IPv4 /24, IPv6 /64) / hash (an HMAC
 *   with APP_KEY: the same address gives the same text, so a new address is still seen)
 * - mail_log_no_body (off by default): the mail send log keeps no body
 */
class LogPrivacy
{
    public const IP_KEEP = 'keep';
    public const IP_TRUNCATE = 'truncate';
    public const IP_HASH = 'hash';
    public const IP_MODES = [self::IP_KEEP, self::IP_TRUNCATE, self::IP_HASH];

    public const MASK = '***';

    /** @var bool|null tests: the setting "keep the input in the logs" without the database */
    public static $storeInput;

    public static function storeInput(): bool
    {
        if (isset(static::$storeInput)) {
            return static::$storeInput;
        }
        try {
            return boolval(System::log_store_input());
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function ipMode(): string
    {
        try {
            $mode = strval(System::log_ip_mode());
        } catch (\Throwable $e) {
            $mode = '';
        }
        return in_array($mode, static::IP_MODES, true) ? $mode : static::IP_KEEP;
    }

    /** Whether the mail send log keeps the body (as before, unless mail_log_no_body is on). */
    public static function mailBody(): bool
    {
        try {
            return !boolval(System::mail_log_no_body());
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * An IP address as the logs keep it.
     */
    public static function ip(?string $ip): ?string
    {
        if (is_nullorempty($ip)) {
            return $ip;
        }
        switch (static::ipMode()) {
            case static::IP_TRUNCATE:
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return preg_replace('/\.\d+$/', '.0', strval($ip));
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $packed = inet_pton(strval($ip));
                    if ($packed !== false) {
                        // an IPv4-mapped address (::ffff:192.0.2.7) is the IPv4 address: /24
                        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                            return preg_replace('/\.\d+$/', '.0', strval(inet_ntop(substr($packed, 12))));
                        }
                        // the network of the client (a home or an office has a /64), without
                        // the part that tells its devices apart (ISS02909)
                        return strval(inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8))) . '/64';
                    }
                }
                return static::MASK;
            case static::IP_HASH:
                return 'h:' . substr(hash_hmac('sha256', strval($ip), strval(config('app.key'))), 0, 16);
        }
        return $ip;
    }

    /**
     * Every value as "***", the keys kept (the input of a request in the operation log).
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function keysOnly(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? static::keysOnly($value) : (is_null($value) || $value === '' ? $value : static::MASK);
        }
        return $data;
    }

    /**
     * The keys of secrets masked anywhere outside the values of a record ("value"): *_token,
     * *_secret, *_key, secret, apikey. A plain "token" or "key" is not: a table of the users may
     * have a column of that name (it is masked on the screens that post one, mask_columns_by_uri).
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function maskSecrets(array $data, bool $inRecord = false): array
    {
        foreach ($data as $key => $value) {
            if (!$inRecord && is_string($key) && preg_match('/(_(token|secret|key)|^secret|^apikey)$/i', $key) === 1) {
                $data[$key] = static::MASK;
            } elseif (is_array($value)) {
                $data[$key] = static::maskSecrets($value, $inRecord || $key === 'value');
            }
        }
        return $data;
    }

    /**
     * A text of the application log without mail addresses, and without the quoted values of SQL
     * (an error of the database writes its SQL with the values).
     */
    public static function maskText(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', static::MASK . '@' . static::MASK, $text) ?? $text;
        if (stripos($text, 'SQL') !== false) {
            // 'value' and "value" (with '' or \' inside)
            $text = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "'" . static::MASK . "'", $text) ?? $text;
            $text = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '"' . static::MASK . '"', $text) ?? $text;
        }
        return $text;
    }
}
