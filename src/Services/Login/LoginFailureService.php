<?php

namespace Exceedone\Exment\Services\Login;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginIpBlock;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

/**
 * Failed login attempts: recorded, then used for the IP block, CAPTCHA and the warning of an account tried from many IP addresses.
 * Never blocks the login itself even if something fails here.
 */
class LoginFailureService
{
    /**
     * Failed attempts older than this days are deleted. (by the schedule)
     */
    public const KEEP_DAYS = 7;

    /**
     * Limits of the settings of the warning.
     */
    public const MULTI_IP_COUNT_MIN = 2;
    public const MULTI_IP_COUNT_MAX = 1000;
    public const MULTI_IP_MINUTES_MAX = 10080;
    public const DEFAULT_MULTI_IP_COUNT = 5;
    public const DEFAULT_MULTI_IP_MINUTES = 60;

    /**
     * Max number of the IP addresses listed in the warning.
     */
    public const MULTI_IP_LIST_MAX = 10;

    /**
     * Record a failed login on the login form, then block the IP address and warn the administrators if needed.
     *
     * @param Request $request
     * @param string $reason LoginFailure::REASON_PASSWORD or REASON_CAPTCHA
     * @param string|null $login_type
     * @param LoginUser|null $login_user the user of the entered ID, if exists.
     * @param string|null $login_provider provider name of LDAP
     * @return LoginIpBlock|null the block, if the IP address is blocked by this failure.
     */
    public function handleLoginFailure(Request $request, string $reason, ?string $login_type, ?LoginUser $login_user = null, ?string $login_provider = null): ?LoginIpBlock
    {
        try {
            $username = $request->input('username');
            $failure = $this->record($request->ip(), $reason, $login_type, $login_user, is_string($username) ? $username : null, $login_provider);
            if (is_null($failure)) {
                return null;
            }

            $this->warnMultiIpIfNeeded($failure);

            return app(LoginIpBlockService::class)->blockIfNeeded($failure);
        } catch (\Throwable $ex) {
            \Log::error($ex);
            return null;
        }
    }

    /**
     * Record a failed attempt.
     *
     * @param string|null $ip
     * @param string $reason LoginFailure::REASON_*
     * @param string|null $login_type
     * @param LoginUser|null $login_user
     * @param string|null $username the entered ID
     * @param string|null $login_provider
     * @return LoginFailure|null null if the table is not migrated yet.
     */
    public function record(?string $ip, string $reason, ?string $login_type, ?LoginUser $login_user = null, ?string $username = null, ?string $login_provider = null): ?LoginFailure
    {
        if (!hasTable(SystemTableName::LOGIN_FAILURE)) {
            return null;
        }

        $failure = new LoginFailure();
        $failure->ip_address = is_nullorempty($ip) ? null : mb_substr((string)$ip, 0, 45);
        $failure->ip_key = LoginIpBlockService::getIpKey($ip);
        $failure->login_user_id = $login_user ? $login_user->id : null;
        $failure->base_user_id = $login_user ? $login_user->base_user_id : null;
        $failure->username = is_nullorempty($username) ? null : mb_substr((string)$username, 0, 255);
        $failure->reason = $reason;
        $failure->login_type = $login_type;
        $failure->login_provider = is_nullorempty($login_provider) ? null : mb_substr((string)$login_provider, 0, 255);
        $failure->save();

        return $failure;
    }

    /**
     * Number of wrong passwords of the entered ID since the time. (Case and accents are ignored by the collation of the database)
     *
     * @param string|null $username
     * @param Carbon $since
     * @return int
     */
    public function countUsernameFailures(?string $username, Carbon $since): int
    {
        if (is_nullorempty($username) || !hasTable(SystemTableName::LOGIN_FAILURE)) {
            return 0;
        }

        return LoginFailure::query()
            ->where('username', mb_substr((string)$username, 0, 255))
            ->where('reason', LoginFailure::REASON_PASSWORD)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Number of wrong passwords of the user since the time, whatever ID was entered. (user code, e-mail)
     *
     * @param mixed $base_user_id
     * @param Carbon $since
     * @return int
     */
    public function countUserFailures($base_user_id, Carbon $since): int
    {
        if (is_nullorempty($base_user_id) || !hasTable(SystemTableName::LOGIN_FAILURE)) {
            return 0;
        }

        return LoginFailure::query()
            ->where('base_user_id', $base_user_id)
            ->where('reason', LoginFailure::REASON_PASSWORD)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Whether the warning of an account tried from many IP addresses is on.
     *
     * @return bool
     */
    public function isMultiIpWarningEnabled(): bool
    {
        return boolval(System::login_multi_ip_warning());
    }

    /**
     * Number of IP addresses to warn.
     *
     * @return int
     */
    public static function getMultiIpCount(): int
    {
        return LoginIpBlockService::toRange(System::login_multi_ip_count(), static::MULTI_IP_COUNT_MIN, static::MULTI_IP_COUNT_MAX, static::DEFAULT_MULTI_IP_COUNT);
    }

    /**
     * Minutes in which the IP addresses are counted.
     *
     * @return int
     */
    public static function getMultiIpMinutes(): int
    {
        return LoginIpBlockService::toRange(System::login_multi_ip_minutes(), 1, static::MULTI_IP_MINUTES_MAX, static::DEFAULT_MULTI_IP_MINUTES);
    }

    /**
     * Warn the system administrators when wrong passwords of an existing user come from many IP addresses:
     * a distributed attack, each IP address tries a few times so as not to be blocked.
     * Warned once in the period per user.
     *
     * @param LoginFailure $failure the failure just recorded
     * @return bool whether warned now
     */
    public function warnMultiIpIfNeeded(LoginFailure $failure): bool
    {
        if (!$this->isMultiIpWarningEnabled() || $failure->reason !== LoginFailure::REASON_PASSWORD
            || is_nullorempty($failure->base_user_id) || is_nullorempty($failure->ip_key)) {
            return false;
        }

        $since = Carbon::now()->subMinutes(static::getMultiIpMinutes());
        $query = function () use ($failure, $since) {
            return LoginFailure::query()
                ->where('base_user_id', $failure->base_user_id)
                ->where('reason', LoginFailure::REASON_PASSWORD)
                ->where('created_at', '>=', $since);
        };

        if ($query()->where('multi_ip_warned', true)->exists()) {
            return false;
        }

        $ip_keys = $query()->whereNotNull('ip_key')->distinct()->pluck('ip_key');
        if ($ip_keys->count() < static::getMultiIpCount()) {
            return false;
        }

        $failure->multi_ip_warned = true;
        $failure->save();

        $this->notifyMultiIp($failure, $ip_keys->count(), $query()->count());
        return true;
    }

    /**
     * @param LoginFailure $failure
     * @param int $ipCount
     * @param int $failureCount
     * @return void
     */
    protected function notifyMultiIp(LoginFailure $failure, int $ipCount, int $failureCount): void
    {
        $login_user = LoginUser::query()->where('id', $failure->login_user_id)->first();
        $user_name = $login_user ? $login_user->user_name : null;
        $user_code = $login_user ? $login_user->user_code : $failure->username;

        $recent = LoginFailure::query()
            ->where('base_user_id', $failure->base_user_id)
            ->where('reason', LoginFailure::REASON_PASSWORD)
            ->whereNotNull('ip_key')
            ->orderBy('id', 'desc')
            ->limit(static::MULTI_IP_LIST_MAX * 10)
            ->pluck('ip_key')
            ->unique()
            ->take(static::MULTI_IP_LIST_MAX)
            ->implode(', ');

        // link to the user: the administrator checks the user, and changes the password if needed.
        LoginHistoryService::notifyAdministrators(
            SystemTableName::USER,
            $failure->base_user_id,
            $failure->base_user_id,
            exmtrans('login_failure.notify.subject', $user_name ?? $user_code),
            function (bool $isHtml) use ($failure, $user_name, $user_code, $ipCount, $failureCount, $recent) {
                return LoginHistoryService::makeNotifyBody(
                    exmtrans('login_failure.notify.body', static::getMultiIpMinutes()),
                    [
                        exmtrans('login_history.user_name') => is_null($user_name) ? $user_code : "{$user_name} ({$user_code})",
                        exmtrans('login_failure.ip_count') => $ipCount,
                        exmtrans('login_failure.failed_count') => $failureCount,
                        exmtrans('login_failure.ip_addresses') => $recent,
                        exmtrans('login_failure.last_failed_at') => $failure->created_at ? $failure->created_at->format('Y-m-d H:i:s') : null,
                    ],
                    admin_urls('data', SystemTableName::USER, $failure->base_user_id),
                    exmtrans('login_failure.notify.link'),
                    $isHtml
                );
            },
            boolval(System::login_brute_force_notify_mail())
        );
    }

    /**
     * Delete failed attempts older than KEEP_DAYS days.
     *
     * @return int deleted count
     */
    public static function deleteOld(): int
    {
        if (!hasTable(SystemTableName::LOGIN_FAILURE)) {
            return 0;
        }
        return LoginFailure::deleteOlderThan(Carbon::now()->subDays(static::KEEP_DAYS));
    }
}
