<?php

namespace Exceedone\Exment\Services\Login;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginIpBlock;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\GeoIp\GeoIpService;

/**
 * IP block: too many failed logins from an IP address in a short time -> the IP address cannot log in for a while.
 *
 * - Counted: wrong ID / password and wrong CAPTCHA on the login form (default / LDAP). (LoginFailure::IP_REASONS)
 *   A wrong one-time password is not counted: it is limited per user. (Auth2factorLockService)
 * - Blocked: posting the login form, by the middleware LoginIpBlock. Pages of the users already logged in, SSO and API are not blocked.
 * - Blocked again within 24 hours after the last block: the block time gets longer (5 minutes -> 30 minutes -> 24 hours by default).
 * - IPv6: blocked per network of the first 64 bits, because a device can change the last 64 bits freely.
 */
class LoginIpBlockService
{
    /**
     * Group of the settings in "systems". See Define::SYSTEM_SETTING_NAME_VALUE
     */
    public const SETTING_GROUP = 'login_brute_force';

    /**
     * Blocked again within this hours after the last block ended: the block time gets longer.
     */
    public const RECURRENCE_HOURS = 24;

    /**
     * Level of the longest block. (3rd block and later)
     */
    public const MAX_LEVEL = 3;

    /**
     * Limits of the settings.
     */
    public const FAILURES_MAX = 1000;
    public const FAILURE_MINUTES_MAX = 1440;
    public const BLOCK_MINUTES_MAX = 10080;

    /**
     * Defaults of the settings, used when a saved value is not usable.
     */
    public const DEFAULT_FAILURE_MINUTES = 5;
    public const DEFAULT_FAILURES = 10;
    public const DEFAULT_BLOCK_MINUTES = [1 => 5, 2 => 30, 3 => 1440];

    /**
     * Whether the IP block is on.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return boolval(System::login_ip_block()) && hasTable(SystemTableName::LOGIN_IP_BLOCK);
    }

    /**
     * Minutes in which failed logins are counted. (Also used for CAPTCHA)
     *
     * @return int
     */
    public static function getFailureMinutes(): int
    {
        return static::toRange(System::login_failure_minutes(), 1, static::FAILURE_MINUTES_MAX, static::DEFAULT_FAILURE_MINUTES);
    }

    /**
     * Number of failed logins to block the IP address.
     *
     * @return int
     */
    public function getMaxFailures(): int
    {
        return static::toRange(System::login_ip_block_failures(), 1, static::FAILURES_MAX, static::DEFAULT_FAILURES);
    }

    /**
     * Block time of the level. The longer times are used only when "longer block when blocked again" is on.
     *
     * @param int $level 1: first block, 2: blocked again, 3: 3rd block and later
     * @return int minutes
     */
    public function getBlockMinutes(int $level): int
    {
        $first = static::toRange(System::login_ip_block_minutes(), 1, static::BLOCK_MINUTES_MAX, static::DEFAULT_BLOCK_MINUTES[1]);
        if ($level <= 1 || !boolval(System::login_ip_block_progressive())) {
            return $first;
        }

        $second = max($first, static::toRange(System::login_ip_block_minutes_2(), 1, static::BLOCK_MINUTES_MAX, static::DEFAULT_BLOCK_MINUTES[2]));
        if ($level == 2) {
            return $second;
        }
        return max($second, static::toRange(System::login_ip_block_minutes_3(), 1, static::BLOCK_MINUTES_MAX, static::DEFAULT_BLOCK_MINUTES[3]));
    }

    /**
     * IP addresses (or CIDR) never blocked. CAPTCHA is not shown to them either.
     *
     * @return array<string>
     */
    public function getAllowedIps(): array
    {
        return IpAddressList::parse(System::login_ip_block_allowed_ips());
    }

    /**
     * Whether the IP address is in "IP addresses never blocked".
     *
     * @param string|null $ip
     * @return bool
     */
    public function isAllowedIp(?string $ip): bool
    {
        return IpAddressList::contains($ip, $this->getAllowedIps());
    }

    /**
     * Key to count failures and block: IPv4 -> the address, IPv6 -> the network of the first 64 bits. ("2001:db8:1:2::/64")
     *
     * @param string|null $ip
     * @return string|null null if not an IP address
     */
    public static function getIpKey(?string $ip): ?string
    {
        $ip = IpAddressList::normalize($ip);
        if (is_null($ip)) {
            return null;
        }

        $packed = (string)inet_pton($ip);
        if (strlen($packed) == 4) {
            return (string)inet_ntop($packed);
        }
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8)) . '/64';
    }

    /**
     * Get the block of the IP address if it is blocked now. (null: the IP block is off, or the IP address is never blocked)
     *
     * @param string|null $ip
     * @return LoginIpBlock|null
     */
    public function getActiveBlock(?string $ip): ?LoginIpBlock
    {
        if (!$this->isEnabled() || $this->isAllowedIp($ip)) {
            return null;
        }

        $key = static::getIpKey($ip);
        if (is_null($key)) {
            return null;
        }

        return LoginIpBlock::query()->where('ip_key', $key)->blocked()->first();
    }

    /**
     * Check the login request before the authentication.
     * If the IP address is blocked, count the attempt and return the response going back to the login page with the message.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|null null: not blocked
     */
    public function handleLoginRequest(Request $request)
    {
        $block = $this->getActiveBlock($request->ip());
        if (is_null($block)) {
            return null;
        }

        LoginIpBlock::query()->where('id', $block->id)->increment('blocked_attempts');

        return static::getBlockedResponse($request, $block);
    }

    /**
     * Response going back to the login page with the message of the block. Keeps the entered ID only. (never the password)
     *
     * @param Request $request
     * @param LoginIpBlock $block
     * @return \Illuminate\Http\RedirectResponse
     */
    public static function getBlockedResponse(Request $request, LoginIpBlock $block)
    {
        return redirect(admin_url('auth/login'))
            ->withInput($request->only('username'))
            ->withErrors(['username' => static::getBlockedMessage($block)]);
    }

    /**
     * Message shown on the login page while the IP address is blocked.
     *
     * @param LoginIpBlock $block
     * @return string
     */
    public static function getBlockedMessage(LoginIpBlock $block): string
    {
        return exmtrans('login.message.ip_blocked', $block->getRemainingMinutes());
    }

    /**
     * Block the IP address of the failure, if it has failed too many times.
     * Failures before the last block are not counted again: they made the last block.
     *
     * @param LoginFailure $failure the failure just recorded
     * @return LoginIpBlock|null the block, if blocked now.
     */
    public function blockIfNeeded(LoginFailure $failure): ?LoginIpBlock
    {
        if (!$this->isEnabled() || !in_array($failure->reason, LoginFailure::IP_REASONS, true)
            || is_nullorempty($failure->ip_key) || $this->isAllowedIp($failure->ip_address)) {
            return null;
        }

        $now = Carbon::now();
        $block = LoginIpBlock::query()->where('ip_key', $failure->ip_key)->first();
        if (!is_null($block) && $block->isBlocked()) {
            // blocked by another request at the same time
            return null;
        }

        $query = LoginFailure::query()
            ->where('ip_key', $failure->ip_key)
            ->whereIn('reason', LoginFailure::IP_REASONS)
            ->where('created_at', '>=', $now->copy()->subMinutes(static::getFailureMinutes()));
        // only the failures after the last block: the failures before it made it.
        if (!is_null($block) && !is_nullorempty($block->last_failure_id)) {
            $query->where('id', '>', $block->last_failure_id);
        }
        $count = $query->count();
        if ($count < $this->getMaxFailures()) {
            return null;
        }

        $level = static::getNextLevel($block, $now);
        $geo = GeoIpService::lookup($failure->ip_address);

        $block = $block ?? new LoginIpBlock();
        $block->ip_key = $failure->ip_key;
        $block->ip_address = $failure->ip_address;
        $block->country_code = $geo['country_code'];
        $block->country = $geo['country'];
        $block->block_level = $level;
        $block->failed_count = $count;
        $block->last_failure_id = $failure->id;
        $block->blocked_attempts = 0;
        $block->last_username = static::truncate($failure->username, 255);
        $block->blocked_at = $now;
        $block->blocked_until = $now->copy()->addMinutes($this->getBlockMinutes($level));
        $block->released_at = null;

        try {
            $block->save();
        }
        // the same IP address was blocked by another request at the same time (unique "ip_key")
        catch (QueryException) {
            return null;
        }

        $this->notifyBlocked($block);

        return $block;
    }

    /**
     * Level of the next block of the IP address: blocked again within RECURRENCE_HOURS after the last block -> 1 level up.
     * Released by an administrator (level 0): starts from 1 again.
     *
     * @param LoginIpBlock|null $block the last block
     * @param Carbon $now
     * @return int
     */
    public static function getNextLevel(?LoginIpBlock $block, Carbon $now): int
    {
        if (is_null($block) || intval($block->block_level) <= 0 || is_null($block->blocked_until)) {
            return 1;
        }
        if ($block->blocked_until->lessThan($now->copy()->subHours(static::RECURRENCE_HOURS))) {
            return 1;
        }
        return min(static::MAX_LEVEL, intval($block->block_level) + 1);
    }

    /**
     * Release the block by an administrator. The next block of the IP address starts from the first block time again.
     *
     * @param LoginIpBlock $block
     * @return void
     */
    public function release(LoginIpBlock $block): void
    {
        $now = Carbon::now();
        if ($block->isBlocked()) {
            $block->blocked_until = $now;
        }
        $block->released_at = $now;
        $block->block_level = 0;
        $block->save();
    }

    /**
     * Delete the records not needed any more: the block ended more than RECURRENCE_HOURS ago.
     *
     * @return int deleted count
     */
    public static function deleteExpired(): int
    {
        if (!hasTable(SystemTableName::LOGIN_IP_BLOCK)) {
            return 0;
        }

        return LoginIpBlock::query()
            ->where('blocked_until', '<', Carbon::now()->subHours(static::RECURRENCE_HOURS))
            ->delete();
    }

    /**
     * Notify system administrators that the IP address was blocked. (setting "login_ip_block_notify")
     *
     * @param LoginIpBlock $block
     * @return void
     */
    protected function notifyBlocked(LoginIpBlock $block): void
    {
        if (!boolval(System::login_ip_block_notify())) {
            return;
        }

        LoginHistoryService::notifyAdministrators(
            SystemTableName::LOGIN_IP_BLOCK,
            $block->id,
            null,
            exmtrans('login_ip_block.notify.subject', $block->ip_key),
            function (bool $isHtml) use ($block) {
                return LoginHistoryService::makeNotifyBody(
                    exmtrans('login_ip_block.notify.body'),
                    [
                        exmtrans('login_ip_block.ip_key') => $block->ip_key,
                        exmtrans('login_ip_block.ip_address') => $block->ip_address,
                        exmtrans('login_ip_block.country') => $block->country_text,
                        exmtrans('login_ip_block.failed_count') => $block->failed_count,
                        exmtrans('login_ip_block.last_username') => $block->last_username,
                        exmtrans('login_ip_block.blocked_at') => static::formatDate($block->blocked_at),
                        exmtrans('login_ip_block.blocked_until') => static::formatDate($block->blocked_until),
                        exmtrans('login_ip_block.block_level') => exmtrans('login_ip_block.block_level_text', $block->block_level),
                    ],
                    admin_urls('login_ip_block', $block->id),
                    exmtrans('login_ip_block.notify.link'),
                    $isHtml
                );
            },
            boolval(System::login_brute_force_notify_mail())
        );
    }

    /**
     * @param Carbon|null $date
     * @return string|null
     */
    protected static function formatDate(?Carbon $date): ?string
    {
        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    /**
     * Saved value of an integer setting, in the range. Out of the range (Ex. edited in the database): the default.
     *
     * @param mixed $value
     * @param int $min
     * @param int $max
     * @param int $default
     * @return int
     */
    public static function toRange($value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $value = intval($value);
        return ($value < $min || $value > $max) ? $default : $value;
    }

    /**
     * @param mixed $value
     * @param int $length
     * @return string|null
     */
    protected static function truncate($value, int $length): ?string
    {
        if (is_nullorempty($value) || !is_scalar($value)) {
            return null;
        }
        return mb_substr((string)$value, 0, $length);
    }
}
