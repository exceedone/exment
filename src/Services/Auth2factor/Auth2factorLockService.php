<?php

namespace Exceedone\Exment\Services\Auth2factor;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Login\LoginFailureService;
use Exceedone\Exment\Services\Login\LoginIpBlockService;

/**
 * Limit of wrong one-time passwords (and recovery codes) of 2factor authentication, per user.
 * Set on the login setting page. ("Protection against brute-force attacks". on by default)
 *
 * Too many wrong codes ("login_2factor_lock_failures", default 5) in "login_2factor_lock_minutes" (default 15):
 * the user is logged out, the one-time passwords sent by mail are invalidated, and 2factor authentication of the user
 * is locked until the period passes. (Logging in with the password again does not reset it)
 * Counted per user, not per IP address, so other users of the same office are not locked.
 * A successful 2factor authentication resets the count.
 */
class Auth2factorLockService
{
    public const DEFAULT_FAILURES = 5;
    public const FAILURES_MAX = 100;
    public const DEFAULT_LOCK_MINUTES = 15;
    public const LOCK_MINUTES_MAX = 1440;

    /**
     * Number of wrong codes to lock.
     *
     * @return int
     */
    public static function getMaxFailures(): int
    {
        return LoginIpBlockService::toRange(System::login_2factor_lock_failures(), 1, static::FAILURES_MAX, static::DEFAULT_FAILURES);
    }

    /**
     * @return int
     */
    public static function getLockMinutes(): int
    {
        return LoginIpBlockService::toRange(System::login_2factor_lock_minutes(), 1, static::LOCK_MINUTES_MAX, static::DEFAULT_LOCK_MINUTES);
    }

    /**
     * Whether the lock is used. (off: wrong codes are limited only by the throttle of "exment.throttle")
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return boolval(System::login_2factor_lock()) && hasTable(SystemTableName::LOGIN_FAILURE);
    }

    /**
     * Remaining minutes of the lock of the user. (rounded up, at least 1 while locked)
     *
     * @param LoginUser $login_user
     * @return int 0: not locked
     */
    public static function getRemainingMinutes(LoginUser $login_user): int
    {
        if (!static::isEnabled()) {
            return 0;
        }

        $max = static::getMaxFailures();
        $failures = LoginFailure::query()
            ->where('login_user_id', $login_user->id)
            ->where('reason', LoginFailure::REASON_2FACTOR)
            ->where('created_at', '>=', Carbon::now()->subMinutes(static::getLockMinutes()))
            ->orderBy('id', 'desc')
            ->limit($max)
            ->pluck('created_at');
        if ($failures->count() < $max) {
            return 0;
        }

        // unlocked when the oldest of the last N failures goes out of the period
        $oldest = Carbon::parse($failures->last());
        $until = $oldest->copy()->addMinutes(static::getLockMinutes());
        $now = Carbon::now();
        if ($until->lessThanOrEqualTo($now)) {
            return 0;
        }
        return max(1, (int)ceil($now->diffInSeconds($until, true) / 60));
    }

    /**
     * @param LoginUser $login_user
     * @return bool
     */
    public static function isLocked(LoginUser $login_user): bool
    {
        return static::getRemainingMinutes($login_user) > 0;
    }

    /**
     * Record a wrong code of the user.
     *
     * @param LoginUser $login_user
     * @return bool true: the user is locked now.
     */
    public static function recordFailure(LoginUser $login_user): bool
    {
        if (!static::isEnabled()) {
            return false;
        }

        try {
            app(LoginFailureService::class)->record(
                request()->ip(),
                LoginFailure::REASON_2FACTOR,
                array_get($login_user, 'login_type'),
                $login_user,
                $login_user->user_code
            );
            return static::isLocked($login_user);
        } catch (\Throwable $ex) {
            \Log::error($ex);
            return false;
        }
    }

    /**
     * Reset the count of the user. (2factor authentication succeeded)
     *
     * @param LoginUser $login_user
     * @return void
     */
    public static function clear(LoginUser $login_user): void
    {
        if (!hasTable(SystemTableName::LOGIN_FAILURE)) {
            return;
        }

        LoginFailure::query()
            ->where('login_user_id', $login_user->id)
            ->where('reason', LoginFailure::REASON_2FACTOR)
            ->delete();
    }

    /**
     * Response for a locked user: the one-time passwords sent by mail are invalidated, the user is logged out,
     * and goes back to the login page with the message.
     *
     * @param LoginUser $login_user
     * @return \Illuminate\Http\RedirectResponse
     */
    public static function getLockedResponse(LoginUser $login_user)
    {
        $minutes = max(1, static::getRemainingMinutes($login_user));

        Auth2factorService::deleteCodes($login_user);

        \Auth::guard(Define::AUTHENTICATE_KEY_WEB)->logout();
        $request = request();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect(admin_url('auth/login'))
            ->withErrors(['username' => exmtrans('2factor.message.locked', $minutes)]);
    }
}
