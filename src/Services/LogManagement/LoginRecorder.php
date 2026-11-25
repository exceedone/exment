<?php

namespace Exceedone\Exment\Services\LogManagement;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginSetting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;

/**
 * Writes the logins of the pages to login_logs (ISS02839): a success, a failure (and why), a
 * lockout, a logout, and a failed 2-step verification. Only the guard of the pages (admin): the
 * API has its own log. A record that cannot be written never stops a login.
 */
class LoginRecorder
{
    public static function register(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if (static::isPages($event->guard)) {
                // a login from a new IP address is found before this login is written (ISS00104)
                $newIp = static::isNewIp($event->user);
                static::write(LoginLog::RESULT_SUCCESS, $event->user);
                if ($newIp) {
                    static::notifyNewIp($event->user);
                }
            }
        });
        Event::listen(Failed::class, function (Failed $event) {
            if (!static::isPages($event->guard)) {
                return;
            }
            $credentials = (array) $event->credentials;
            // what was typed is not kept: it may be a password typed in the wrong field (part 4).
            // A known user is told by the ids, an unknown one by the reason
            static::write(LoginLog::RESULT_FAILURE, $event->user, [
                'failure_reason' => isset($event->user) ? LoginLog::REASON_WRONG_PASSWORD : LoginLog::REASON_UNKNOWN_USER,
                'provider' => strval(array_get($credentials, 'login_type') ?: LoginType::PURE),
                'provider_name' => ($credentials['login_setting'] ?? null) instanceof LoginSetting ? strval($credentials['login_setting']->provider_name) : null,
            ]);
        });
        Event::listen(Lockout::class, function (Lockout $event) {
            static::write(LoginLog::RESULT_LOCKOUT, null);
        });
        Event::listen(Logout::class, function (Logout $event) {
            if (static::isPages($event->guard)) {
                static::write(LoginLog::RESULT_LOGOUT, $event->user);
            }
        });
    }

    /**
     * A failed 2-step verification (the code of the mail or of Google).
     *
     * @param mixed $user the login user
     */
    public static function twoFactorFailed($user): void
    {
        static::write(LoginLog::RESULT_FAILURE, $user, ['failure_reason' => LoginLog::REASON_2FA_FAILED]);
    }

    /**
     * @param mixed $user
     */
    protected static function isNewIp($user): bool
    {
        try {
            $ip = mb_substr(strval(LogPrivacy::ip(request()->ip())), 0, 64);
            return LoginHistory::isNewIp($user, $ip);
        } catch (\Throwable $e) {
            \Log::warning('Exment: a login from a new IP address was not checked: ' . get_class($e));
            return false;
        }
    }

    /**
     * @param mixed $user
     */
    protected static function notifyNewIp($user): void
    {
        try {
            LoginHistory::notifyNewIp($user);
        } catch (\Throwable $e) {
            \Log::warning('Exment: the notice of a login from a new IP address was not made: ' . get_class($e));
        }
    }

    protected static function isPages(?string $guard): bool
    {
        return in_array(strval($guard), ['admin', strval(config('admin.auth.guard', 'admin'))], true);
    }

    /**
     * How a user logs in: pure (ID and password), or the type of their login setting.
     *
     * @param mixed $user
     * @return array{0: string, 1: string|null} the type and the name of the provider
     */
    protected static function providerOf($user): array
    {
        $name = is_object($user) ? $user->login_provider ?? null : null;
        if (is_nullorempty($name)) {
            return [LoginType::PURE, null];
        }
        try {
            $setting = LoginSetting::getAllSettings(false)->first(function ($setting) use ($name) {
                return $setting->provider_name === $name;
            });
            return [isset($setting) ? strval($setting->login_type) : LoginType::OAUTH, strval($name)];
        } catch (\Throwable $e) {
            return [LoginType::OAUTH, strval($name)];
        }
    }

    /**
     * @param mixed $user
     * @param array<string, mixed> $values
     */
    protected static function write(string $result, $user, array $values = []): void
    {
        try {
            if (!LoginLog::tableExists()) {
                return;
            }
            [$provider, $providerName] = static::providerOf($user);
            $request = request();
            LoginLog::create(array_merge([
                'login_user_id' => is_object($user) ? $user->id ?? null : null,
                'base_user_id' => is_object($user) ? $user->base_user_id ?? null : null,
                'login_name' => is_object($user) ? mb_substr(strval($user->username ?? ''), 0, 256) ?: null : null,
                'provider' => $provider,
                'provider_name' => $providerName,
                'result' => $result,
                'ip' => mb_substr(strval(LogPrivacy::ip($request->ip())), 0, 64) ?: null,
                'user_agent' => mb_substr(strval($request->userAgent()), 0, 512) ?: null,
                'created_at' => \Carbon\Carbon::now(),
            ], array_filter($values, function ($value) {
                return !is_null($value);
            })));
        } catch (\Throwable $e) {
            \Log::warning('Exment: the login was not recorded: ' . get_class($e));
        }
    }
}
