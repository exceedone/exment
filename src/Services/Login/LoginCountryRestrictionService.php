<?php

namespace Exceedone\Exment\Services\Login;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\IpUtils;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\GeoIp\GeoIpService;

/**
 * Login restriction by country: login only from the allowed countries, judged by the IP address with GeoIP.
 *
 * Checked when logging in (not on every page after login):
 * - login form (default / LDAP) and SSO (OAuth / SAML): before the authentication, by the middleware LoginCountryRestriction.
 *   So a blocked user cannot know whether the password is correct.
 * - "remember me" cookie: handleLogin(), listener of the login event.
 * Blocked attempts are recorded in the login history. (blocked_reason)
 *
 * Resolved from the service container, so that tests can replace the GeoIP lookup.
 */
class LoginCountryRestrictionService
{
    /**
     * Group of the settings in "systems". See Define::SYSTEM_SETTING_NAME_VALUE
     */
    public const SETTING_GROUP = 'login_country';

    /**
     * Internal networks. Logins from them are always allowed: the country cannot be judged, and they are not accesses from abroad.
     */
    public const INTERNAL_NETWORKS = [
        // private (RFC 1918), shared address space of ISP (RFC 6598)
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        // loopback, link-local
        '127.0.0.0/8',
        '169.254.0.0/16',
        '::1/128',
        'fe80::/10',
        // unique local (RFC 4193)
        'fc00::/7',
    ];

    /**
     * Whether the restriction is on.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return boolval(System::login_country_restriction());
    }

    /**
     * Get the saved settings.
     *
     * @return array{enabled: bool, countries: array<string>, unknown_allowed: bool, ips: array<string>}
     */
    public function getSettings(): array
    {
        return static::makeSettings(
            $this->isEnabled(),
            System::login_country_allowed(),
            boolval(System::login_country_unknown_allowed()),
            System::login_country_allowed_ips()
        );
    }

    /**
     * Make settings from the values. (Also used for the values on the setting form before saving)
     *
     * @param bool $enabled
     * @param mixed $countries array of country codes
     * @param bool $unknown_allowed
     * @param mixed $ips IP addresses (or CIDR) separated by line feed
     * @return array{enabled: bool, countries: array<string>, unknown_allowed: bool, ips: array<string>}
     */
    public static function makeSettings(bool $enabled, $countries, bool $unknown_allowed, $ips): array
    {
        $codes = [];
        foreach ((array)$countries as $country) {
            if (is_string($country) && $country !== '') {
                $codes[] = strtoupper(trim($country));
            }
        }

        return [
            'enabled' => $enabled,
            'countries' => array_values(array_unique($codes)),
            'unknown_allowed' => $unknown_allowed,
            'ips' => static::parseIps($ips),
        ];
    }

    /**
     * Resolve country and location of the IP address.
     *
     * @param string|null $ip
     * @return array{country_code: string|null, country: string|null, region: string|null, city: string|null}
     */
    public function lookup(?string $ip): array
    {
        return GeoIpService::lookup($ip);
    }

    /**
     * Check whether login from the IP address is allowed.
     *
     * @param string|null $ip
     * @param array{enabled: bool, countries: array<string>, unknown_allowed: bool, ips: array<string>}|null $settings null: the saved settings
     * @return array{allowed: bool, geo: array{country_code: string|null, country: string|null, region: string|null, city: string|null}}
     */
    public function check(?string $ip, ?array $settings = null): array
    {
        $settings = $settings ?? $this->getSettings();
        // off: no lookup. (lookup(null) only gives the empty values)
        $geo = $settings['enabled'] ? $this->lookup($ip) : GeoIpService::lookup(null);

        return [
            'allowed' => static::isAllowedBy($settings, $ip, $geo['country_code']),
            'geo' => $geo,
        ];
    }

    /**
     * Judge by the settings, in this order:
     * 1. the restriction is off: allowed
     * 2. the IP address is in "IP addresses always allowed": allowed
     * 3. IP address of an internal network (INTERNAL_NETWORKS): allowed.
     * 4. the country cannot be judged: by the setting "allow login when the country is unknown"
     * 5. the country is in the allowed countries: allowed. Otherwise blocked.
     *
     * @param array{enabled: bool, countries: array<string>, unknown_allowed: bool, ips: array<string>} $settings
     * @param string|null $ip
     * @param string|null $country_code
     * @return bool
     */
    public static function isAllowedBy(array $settings, ?string $ip, ?string $country_code): bool
    {
        if (!$settings['enabled']) {
            return true;
        }

        $ip = static::normalizeIp($ip);
        if (is_null($ip)) {
            return $settings['unknown_allowed'];
        }

        if (count($settings['ips']) > 0 && IpUtils::checkIp($ip, $settings['ips'])) {
            return true;
        }

        if (static::isInternalIp($ip)) {
            return true;
        }

        if (is_nullorempty($country_code)) {
            return $settings['unknown_allowed'];
        }

        return in_array(strtoupper($country_code), $settings['countries'], true);
    }

    /**
     * Check the login request (login form or SSO) before the authentication.
     * If blocked, record the attempt and return the response going back to the login page with the message.
     *
     * @param Request $request
     * @param string $login_type LoginType
     * @param string|null $login_provider provider name of LDAP / OAuth / SAML
     * @return \Illuminate\Http\RedirectResponse|null null: allowed
     */
    public function handleLoginRequest(Request $request, string $login_type, ?string $login_provider = null)
    {
        $result = $this->check($request->ip());
        if ($result['allowed']) {
            return null;
        }

        $isForm = !static::isSso($login_type);
        $username = $isForm ? $request->input('username') : null;

        LoginHistoryService::recordBlocked(
            LoginHistory::BLOCKED_REASON_COUNTRY,
            $login_type,
            $result['geo'],
            null,
            is_string($username) ? $username : null,
            $login_provider
        );

        $response = redirect(admin_url('auth/login'))
            ->withErrors([static::getErrorKey($login_type) => static::getBlockedMessage()]);
        if ($isForm) {
            // keep the entered ID only. (never flash the password)
            $response->withInput($request->only('username'));
        }
        return $response;
    }

    /**
     * Listener of Illuminate\Auth\Events\Login, registered before the login history listener.
     * Checks the login by the "remember me" cookie. (Other logins are checked before the authentication, see handleLoginRequest)
     * If blocked, the user is logged out of this device, the attempt is recorded, and returns false to stop the other listeners
     * (the login history must not record it as a login).
     *
     * @param Login $event
     * @return false|null
     */
    public function handleLogin(Login $event)
    {
        if ($event->guard != Define::AUTHENTICATE_KEY_WEB || !($event->user instanceof LoginUser) || !request()->hasSession()) {
            return null;
        }

        $guard = \Auth::guard($event->guard);
        if (!($guard instanceof SessionGuard) || !$guard->viaRemember()) {
            return null;
        }

        $request = request();
        $result = $this->check($request->ip());
        if ($result['allowed']) {
            return null;
        }

        $login_user = $event->user;
        $login_type = array_get($login_user, 'login_type') ?? LoginType::PURE;

        LoginHistoryService::recordBlocked(
            LoginHistory::BLOCKED_REASON_COUNTRY,
            $login_type,
            $result['geo'],
            $login_user,
            null,
            null,
            true
        );

        // also forgets the "remember me" cookie of this device, so that the next request does not try again.
        $guard->logoutCurrentDevice();

        // shown on the login page the user is redirected to
        $request->session()->flash('errors', (new ViewErrorBag())->put('default', new MessageBag([
            static::getErrorKey($login_type) => static::getBlockedMessage(),
        ])));

        return false;
    }

    /**
     * Message shown on the login page when blocked. Does not tell which countries are allowed.
     *
     * @return string
     */
    public static function getBlockedMessage(): string
    {
        return exmtrans('login.message.country_restricted');
    }

    /**
     * Error key of the login page. The message of SSO is shown under the SSO buttons.
     *
     * @param string $login_type
     * @return string
     */
    protected static function getErrorKey(string $login_type): string
    {
        return static::isSso($login_type) ? 'sso_error' : 'username';
    }

    /**
     * @param string $login_type
     * @return bool
     */
    protected static function isSso(string $login_type): bool
    {
        return in_array($login_type, [LoginType::OAUTH, LoginType::SAML], true);
    }

    /**
     * Parse "IP addresses always allowed". 1 per line, blank lines are ignored.
     *
     * @param mixed $text
     * @return array<string>
     */
    public static function parseIps($text): array
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
     * Get the lines which are not an IP address nor CIDR. (Ex. "192.168.0.0/16", "2001:db8::/32")
     *
     * @param mixed $text
     * @return array<string>
     */
    public static function getInvalidIps($text): array
    {
        return array_values(array_filter(static::parseIps($text), function ($line) {
            return !static::isValidIpOrCidr($line);
        }));
    }

    /**
     * @param string $value
     * @return bool
     */
    protected static function isValidIpOrCidr(string $value): bool
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
    protected static function normalizeIp(?string $ip): ?string
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
     * IP address of an internal network. (company network, the server itself)
     * Tunnel addresses (6to4, Teredo) are not included: they are used from anywhere.
     *
     * @param string $ip
     * @return bool
     */
    protected static function isInternalIp(string $ip): bool
    {
        return IpUtils::checkIp($ip, static::INTERNAL_NETWORKS);
    }
}
