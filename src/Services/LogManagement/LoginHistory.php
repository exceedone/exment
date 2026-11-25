<?php

namespace Exceedone\Exment\Services\LogManagement;

use Carbon\Carbon;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Auth2factor\LoginContext;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Support\Collection;

/**
 * The logins of a user for the user (ISS00104): the user's own login history on the page of the
 * user setting, and a notice when a login comes from an IP address the user has not logged in
 * from in the last days (login_notify_new_ip, and login_notify_new_ip_mail for a mail too).
 */
class LoginHistory
{
    /** The logins shown on the page of the user setting. */
    public const ROWS = 20;

    /** An IP address not used in a login of the user in these days is "new". */
    public const NEW_IP_DAYS = 90;

    /**
     * The newest logins of the login user (only the user's own).
     *
     * @return Collection<int, LoginLog>
     */
    public static function mine(int $limit = self::ROWS): Collection
    {
        $userId = \Exment::getUserId();
        if (is_nullorempty($userId) || !LoginLog::tableExists()) {
            return collect();
        }
        return LoginLog::query()
            ->where('base_user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The box of the login history for the page of the user setting, or null when there is no
     * table of the logins (a site not migrated yet).
     */
    public static function box(): ?\ExmentAdminCore\Admin\Widgets\Box
    {
        if (!LoginLog::tableExists() || is_nullorempty(\Exment::getUserId())) {
            return null;
        }
        $view = view('exment::auth.login_history', [
            'logins' => static::mine(),
            'rows' => static::ROWS,
        ]);
        return new \ExmentAdminCore\Admin\Widgets\Box(exmtrans('login.history_header'), $view->render());
    }

    /**
     * Whether a login of the user (before it is written) comes from a new IP address: the setting
     * is on, the user has logged in before, and not from this address in the last days.
     *
     * @param mixed $user the login user
     * @param string|null $ip the address as the login records keep it (LogPrivacy::ip)
     */
    public static function isNewIp($user, ?string $ip): bool
    {
        if (!boolval(System::login_notify_new_ip())) {
            return false;
        }
        $baseUserId = is_object($user) ? $user->base_user_id ?? null : null;
        if (is_nullorempty($baseUserId) || is_nullorempty($ip) || !LoginLog::tableExists()) {
            return false;
        }
        $query = LoginLog::query()
            ->where('base_user_id', $baseUserId)
            ->where('result', LoginLog::RESULT_SUCCESS);
        // the first login of the user is not from a "new" place
        if (!(clone $query)->exists()) {
            return false;
        }
        return !$query->where('ip', $ip)
            ->where('created_at', '>=', Carbon::now()->subDays(static::NEW_IP_DAYS))
            ->exists();
    }

    /**
     * Tell the user of the login from a new IP address: the bell, and a mail when
     * login_notify_new_ip_mail is on (sent after the response, so the login does not wait).
     *
     * @param mixed $user the login user
     */
    public static function notifyNewIp($user): void
    {
        $baseUserId = is_object($user) ? $user->base_user_id ?? null : null;
        if (is_nullorempty($baseUserId)) {
            return;
        }
        $prms = LoginContext::prms();
        $subject = strval(exmtrans('login.new_ip_subject'));
        $body = strval(exmtrans('login.new_ip_body', [
            'days' => static::NEW_IP_DAYS,
            'datetime' => $prms['login_datetime'],
            'ip' => $prms['login_ip'],
            'browser' => $prms['login_user_agent'],
        ]));

        $navbar = new NotifyNavbar();
        $navbar->fill([
            'notify_id' => 0,
            'target_user_id' => $baseUserId,
            'notify_subject' => mb_substr($subject, 0, 200),
            'notify_body' => mb_substr($body, 0, 2000),
            'trigger_user_id' => null,
        ]);
        $navbar->save();

        if (!boolval(System::login_notify_new_ip_mail())) {
            return;
        }
        $baseUser = $user->base_user ?? null;
        if (!isset($baseUser)) {
            return;
        }
        app()->terminating(function () use ($baseUser, $subject, $body) {
            try {
                if (!is_null(NotifyService::getAddress($baseUser))) {
                    NotifyService::notifyMail(['to' => $baseUser, 'subject' => $subject, 'body' => $body]);
                }
            } catch (\Throwable $e) {
                \Log::warning('Exment: the mail of a login from a new IP address was not sent: ' . get_class($e));
            }
        });
    }
}
