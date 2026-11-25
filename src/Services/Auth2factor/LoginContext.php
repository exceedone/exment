<?php

namespace Exceedone\Exment\Services\Auth2factor;

use Illuminate\Http\Request;

/**
 * Where a login comes from, for the mails of the 2-step verification (ISS00268): the IP address,
 * the country and the region when they are known, the browser and the time.
 *
 * The country and the region come only from the headers of a CDN in front of the site
 * (CloudFront: CloudFront-Viewer-Country, CloudFront-Viewer-Country-Region(-Name); Cloudflare:
 * CF-IPCountry), and only when EXMENT_LOGIN_LOCATION_HEADERS=true: without a CDN in front, anyone
 * could send such a header. The IP address is not sent anywhere to be looked up.
 */
class LoginContext
{
    /**
     * The values for the mail templates: ${login_ip}, ${login_country}, ${login_region},
     * ${login_location} (the country and the region, or "unknown"), ${login_user_agent},
     * ${login_datetime}.
     *
     * @return array<string, string>
     */
    public static function prms(?Request $request = null): array
    {
        $request = $request ?? request();
        $unknown = strval(exmtrans('2factor.login_unknown'));
        [$country, $region] = static::location($request);
        $location = implode(strval(exmtrans('2factor.login_location_separator')), array_filter([$country, $region], function ($v) {
            return $v !== '';
        }));

        return [
            'login_ip' => strval($request->ip()) ?: $unknown,
            'login_country' => $country !== '' ? $country : $unknown,
            'login_region' => $region !== '' ? $region : $unknown,
            'login_location' => $location !== '' ? $location : $unknown,
            'login_user_agent' => static::browser(strval($request->userAgent())) ?? $unknown,
            'login_datetime' => \Carbon\Carbon::now()->format('Y/m/d H:i'),
        ];
    }

    /**
     * The country and the region from the headers of the CDN ('' when not known).
     *
     * @return array{0: string, 1: string}
     */
    public static function location(Request $request): array
    {
        if (!boolval(config('exment.login_location_headers', false))) {
            return ['', ''];
        }
        $code = strtoupper(trim(strval($request->header('CloudFront-Viewer-Country') ?: $request->header('CF-IPCountry'))));
        // XX: unknown, T1: Tor (Cloudflare)
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || in_array($code, ['XX', 'T1'], true)) {
            return ['', ''];
        }
        $country = $code;
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $code, strval(app()->getLocale()));
            if (is_string($name) && $name !== '' && $name !== $code) {
                $country = $name;
            }
        }
        $region = trim(strval($request->header('CloudFront-Viewer-Country-Region-Name') ?: $request->header('CloudFront-Viewer-Country-Region')));
        // a header is one line of plain text
        $region = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $region) ?? '', 0, 64);
        return [$country, $region];
    }

    /**
     * "Chrome (Windows)" from a User-Agent, or null when it is not known.
     */
    public static function browser(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }
        $browsers = [
            'Edge' => '/\bEdg(e|A|iOS)?\//',
            'Opera' => '/\b(OPR|Opera)\//',
            'Firefox' => '/\b(Firefox|FxiOS)\//',
            'Chrome' => '/\b(Chrome|CriOS)\//',
            'Safari' => '/\bVersion\/[\d.]+.*Safari\//',
        ];
        $systems = [
            'iOS' => '/\b(iPhone|iPad|iPod)\b/',
            'Android' => '/\bAndroid\b/',
            'Windows' => '/\bWindows\b/',
            'macOS' => '/\bMac OS X\b/',
            'ChromeOS' => '/\bCrOS\b/',
            'Linux' => '/\bLinux\b/',
        ];
        $browser = null;
        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                $browser = $name;
                break;
            }
        }
        $system = null;
        foreach ($systems as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                $system = $name;
                break;
            }
        }
        if (!isset($browser) && !isset($system)) {
            return null;
        }
        return isset($browser, $system) ? "$browser ($system)" : strval($browser ?? $system);
    }
}
