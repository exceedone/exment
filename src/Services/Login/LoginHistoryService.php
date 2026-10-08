<?php

namespace Exceedone\Exment\Services\Login;

use Illuminate\Auth\Events\Login;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\MailBodyType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Services\GeoIp\GeoIpService;

/**
 * Record login history, and notify system administrators of a login from an unusual IP address.
 */
class LoginHistoryService
{
    /**
     * Default number of recent logins compared with, to judge "login from a new IP address".
     */
    public const DEFAULT_NEW_IP_COUNT = 10;

    /**
     * Login types that are not subject to 2factor authentication: the identity provider has already authenticated the user,
     * so no OTP is asked after login. (AuthOAuthController / AuthSamlController mark the session as verified right after login.)
     */
    public const LOGIN_TYPES_WITHOUT_2FACTOR = [LoginType::OAUTH, LoginType::SAML];

    /**
     * Listener of Illuminate\Auth\Events\Login.
     * Fired on every successful login to the admin page (default form, LDAP, OAuth, SAML, remember me).
     *
     * @param Login $event
     * @return void
     */
    public static function handleLogin(Login $event): void
    {
        if ($event->guard != Define::AUTHENTICATE_KEY_WEB) {
            return;
        }

        $login_user = $event->user;
        if (!($login_user instanceof LoginUser)) {
            return;
        }

        // A login from browser always has session. No session: not a login from browser. (Ex. test data seeder)
        if (!request()->hasSession()) {
            return;
        }

        try {
            // $event->remember is also true when the user checked "remember me" on the login form,
            // so ask the guard whether the user was logged in by the "remember me" cookie.
            $guard = \Auth::guard($event->guard);
            $viaRemember = method_exists($guard, 'viaRemember') && $guard->viaRemember();

            static::record($login_user, $viaRemember);
        }
        // Login must not be blocked even if recording history fails.
        catch (\Throwable $ex) {
            \Log::error($ex);
        }
    }

    /**
     * Record login history of the login user.
     *
     * @param LoginUser $login_user
     * @param bool $viaRemember whether logged in by "remember me" cookie
     * @return LoginHistory|null null if the table is not migrated yet.
     */
    public static function record(LoginUser $login_user, bool $viaRemember = false): ?LoginHistory
    {
        // before executing "exment:update"
        if (!hasTable(SystemTableName::LOGIN_HISTORY)) {
            return null;
        }

        $request = request();
        $ip = $request->ip();
        $geo = GeoIpService::lookup($ip);
        $user_agent = $request->userAgent();

        $login_type = array_get($login_user, 'login_type') ?? LoginType::PURE;

        $history = new LoginHistory();
        // The login user is not set to the guard yet when the login event is fired, so set users directly.
        $history->saving_users = false;
        $history->login_user_id = $login_user->id;
        $history->base_user_id = $login_user->base_user_id;
        $history->user_code = static::truncate($login_user->user_code, 255);
        $history->user_name = static::truncate($login_user->user_name, 255);
        $history->login_type = $login_type;
        $history->login_provider = $login_user->login_provider;
        $history->ip_address = $ip;
        $history->country_code = $geo['country_code'];
        $history->country = $geo['country'];
        $history->region = $geo['region'];
        $history->city = $geo['city'];
        $history->user_agent = static::truncate($user_agent, 1000);
        $history->is_new_ip = static::isNewIp($login_user->base_user_id, $ip);
        $history->via_remember = $viaRemember;
        // null: not subject to 2factor. false: 2factor is required, and marked true by verified2factor() after passing it.
        $history->auth_2factor_verified = static::requires2factor($login_type) ? false : null;
        $history->created_user_id = $login_user->base_user_id;
        $history->updated_user_id = $login_user->base_user_id;
        $history->save();

        if ($request->hasSession()) {
            $request->session()->put(Define::SYSTEM_KEY_SESSION_LOGIN_HISTORY_ID, $history->id);
        }

        if ($history->is_new_ip) {
            static::notifyNewIp($history);
        }

        return $history;
    }

    /**
     * Notify system administrators that the user logged in from an IP address the user has not used.
     * Always notified on the page (bell icon of the header), and also by mail if the setting is on.
     * A failure of notification never blocks login.
     *
     * @param LoginHistory $history
     * @return void
     */
    public static function notifyNewIp(LoginHistory $history): void
    {
        // The warning is still recorded and shown on the list even if notification is turned off.
        if (!boolval(System::login_history_notify_new_ip())) {
            return;
        }

        try {
            $subject = static::getNotifySubject($history);
            $body = static::getNotifyBody($history, true);

            foreach (static::getAdminUserIds() as $admin_id) {
                $notify = new NotifyNavbar();
                $notify->saving_users = false;
                $notify->notify_id = 0;
                // link to the login history. See NotifyNavbar::isLoginHistory()
                $notify->parent_type = SystemTableName::LOGIN_HISTORY;
                $notify->parent_id = $history->id;
                $notify->target_user_id = $admin_id;
                $notify->trigger_user_id = $history->base_user_id;
                $notify->notify_subject = mb_substr($subject, 0, 200);
                $notify->notify_body = mb_substr($body, 0, 2000);
                $notify->created_user_id = $history->base_user_id;
                $notify->updated_user_id = $history->base_user_id;
                $notify->save();
            }
        } catch (\Throwable $ex) {
            \Log::error($ex);
        }

        if (boolval(System::login_history_notify_mail())) {
            // Sending mail may take time. Send after the response is sent, so that login is not delayed.
            // Terminating callbacks are kept while the application lives, so make sure to send only once.
            $sent = false;
            app()->terminating(function () use ($history, &$sent) {
                if ($sent) {
                    return;
                }
                $sent = true;

                static::sendNotifyMail($history);
            });
        }
    }

    /**
     * Send the warning mail to system administrators.
     *
     * @param LoginHistory $history
     * @return void
     */
    public static function sendNotifyMail(LoginHistory $history): void
    {
        try {
            $user_table = CustomTable::getEloquent(SystemTableName::USER);
            $addresses = static::getAdminUserIds()->map(function ($admin_id) use ($user_table) {
                $user = $user_table->getValueModel($admin_id);
                return $user ? $user->getValue('email') : null;
            })->filter()->unique()->values()->toArray();

            if (count($addresses) == 0) {
                return;
            }

            $isHtml = !isMatchString(System::system_mail_body_type(), MailBodyType::PLAIN);

            MailSender::make(null, $addresses)
                ->subject(static::escapeMailFormat(static::getNotifySubject($history)))
                ->body(static::escapeMailFormat(static::getNotifyBody($history, $isHtml)))
                ->send();
        } catch (\Throwable $ex) {
            \Log::error($ex);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, mixed> user ids of system administrators
     */
    protected static function getAdminUserIds(): \Illuminate\Support\Collection
    {
        return collect(System::system_admin_users())->filter()->unique()->values();
    }

    /**
     * @param LoginHistory $history
     * @return string
     */
    protected static function getNotifySubject(LoginHistory $history): string
    {
        return exmtrans('login_history.notify.subject', $history->user_name ?? $history->user_code);
    }

    /**
     * Get the notification body. Lines are separated by line feed.
     *
     * @param LoginHistory $history
     * @param bool $isHtml true: values are escaped and the link is an anchor tag. false: plain text.
     * @return string
     */
    protected static function getNotifyBody(LoginHistory $history, bool $isHtml): string
    {
        $items = [
            exmtrans('login_history.user_name') => "{$history->user_name} ({$history->user_code})",
            exmtrans('login_history.login_at') => $history->created_at ? $history->created_at->format('Y-m-d H:i:s') : null,
            exmtrans('login_history.ip_address') => $history->ip_address,
            exmtrans('login_history.country') => $history->country_text,
            exmtrans('login_history.location') => $history->location,
            exmtrans('login_history.login_type') => $history->login_type_text,
        ];

        $lines = [exmtrans('login_history.notify.body'), ''];
        foreach ($items as $label => $value) {
            if (is_nullorempty($value)) {
                continue;
            }
            $lines[] = exmtrans('common.format_keyvalue', $label, $value);
        }
        $lines[] = '';

        $url = admin_urls('login_history', $history->id);
        $link = exmtrans('login_history.notify.link');
        if (!$isHtml) {
            $lines[] = exmtrans('common.format_keyvalue', $link, $url);
            return implode("\n", $lines);
        }

        $lines = array_map('esc_html', $lines);
        $lines[] = '<a href="' . esc_html($url) . '">' . esc_html($link) . '</a>';
        return implode("\n", $lines);
    }

    /**
     * Mail subject and body are processed as a format ("${...}" is replaced with a value).
     * The text contains values the user can set (Ex. user name), so prevent them from being processed as a format.
     *
     * @param string $text
     * @return string
     */
    protected static function escapeMailFormat(string $text): string
    {
        return str_replace('${', '$ {', $text);
    }

    /**
     * Mark the login history of this session as "2factor verified".
     * Call after the login user passed 2factor authentication.
     *
     * @return void
     */
    public static function verified2factor(): void
    {
        try {
            $id = session(Define::SYSTEM_KEY_SESSION_LOGIN_HISTORY_ID);
            if (is_nullorempty($id) || !hasTable(SystemTableName::LOGIN_HISTORY)) {
                return;
            }

            LoginHistory::query()
                ->where('id', $id)
                ->where('base_user_id', \Exment::getUserId())
                ->where('auth_2factor_verified', false)
                ->update(['auth_2factor_verified' => true]);
        } catch (\Throwable $ex) {
            \Log::error($ex);
        }
    }

    /**
     * Whether the user logs in from an IP address the user has not used recently.
     *
     * Compared with the IP addresses of the user's last N successful logins
     * (N = setting "login_history_new_ip_count", default 10).
     * If the user has no history yet (first login), there is nothing to compare with, so returns false.
     * Histories that did not pass 2factor authentication are not treated as "used".
     *
     * @param mixed $base_user_id
     * @param string|null $ip
     * @param int|null $count number of recent logins to compare with. null: the setting value.
     * @return bool
     */
    public static function isNewIp($base_user_id, ?string $ip, ?int $count = null): bool
    {
        if (is_nullorempty($base_user_id) || is_nullorempty($ip)) {
            return false;
        }

        $count = $count ?? static::getNewIpCount();

        $used_ips = LoginHistory::query()
            ->where('base_user_id', $base_user_id)
            ->whereNotNull('ip_address')
            ->where(function ($query) {
                $query->whereNull('auth_2factor_verified')
                    ->orWhere('auth_2factor_verified', true);
            })
            ->orderBy('id', 'desc')
            ->limit($count)
            ->pluck('ip_address');
        if ($used_ips->isEmpty()) {
            return false;
        }

        $key = static::getIpNetworkKey($ip);
        return !$used_ips->contains(function ($used_ip) use ($key) {
            return static::getIpNetworkKey($used_ip) === $key;
        });
    }

    /**
     * Number of recent logins compared with, to judge "login from a new IP address".
     * Setting "login_history_new_ip_count". Always 1 or more.
     *
     * @return int
     */
    public static function getNewIpCount(): int
    {
        $count = intval(System::login_history_new_ip_count());
        return $count > 0 ? $count : static::DEFAULT_NEW_IP_COUNT;
    }

    /**
     * Get the key for comparing IP addresses.
     * IPv4: the address itself. IPv6: the first 64 bits (network prefix),
     * because the last 64 bits of a device change frequently by privacy extensions.
     *
     * @param string $ip
     * @return string
     */
    public static function getIpNetworkKey(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        if (strlen($packed) == 16) {
            // IPv4-mapped IPv6 address (::ffff:192.0.2.1)
            if (substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
                return (string)inet_ntop(substr($packed, 12));
            }
            return bin2hex(substr($packed, 0, 8));
        }

        return (string)inet_ntop($packed);
    }

    /**
     * Whether 2factor authentication (OTP) is required after a login of the login type.
     * 2factor is used only for the default login and LDAP. OAuth / SAML logins are not subject to it.
     *
     * @param string $login_type
     * @return bool
     */
    public static function requires2factor(string $login_type): bool
    {
        return static::isUse2factor() && !in_array($login_type, static::LOGIN_TYPES_WITHOUT_2FACTOR);
    }

    /**
     * Whether 2factor authentication is enabled in the system.
     *
     * @return bool
     */
    protected static function isUse2factor(): bool
    {
        return boolval(config('exment.login_use_2factor', false)) && boolval(System::login_use_2factor());
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
