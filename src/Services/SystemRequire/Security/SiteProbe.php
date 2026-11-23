<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Illuminate\Support\Facades\Http;

/**
 * Asks the site itself for a page, as a visitor would (the security check). A site
 * that cannot reach its own address (a container, a firewall) answers null: the item
 * is then "could not check", never "OK".
 */
class SiteProbe
{
    /** @var array<string, array{status: int, headers: array<string, string>}|null> */
    protected static $cache = [];

    /**
     * The address of the site: EXMENT_SITE_PROBE_URL, else APP_URL. Null when neither is
     * set: the probes are then skipped ("could not check").
     */
    public static function base(): ?string
    {
        // EXMENT_SITE_PROBE_URL: where this server reaches the site when APP_URL is not reachable
        // from inside it (a container: http://web for docker-exment)
        $base = rtrim(strval(config('exment.site_probe_url')) ?: strval(config('app.url')), '/');
        return $base === '' ? null : $base;
    }

    /**
     * @return array{status: int, headers: array<string, string>}|null
     */
    public static function get(string $path): ?array
    {
        $base = static::base();
        if (!isset($base)) {
            return null;
        }
        $url = $base . '/' . ltrim($path, '/');
        if (array_key_exists($url, static::$cache)) {
            return static::$cache[$url];
        }
        try {
            $response = Http::timeout(3)->withoutRedirecting()->get($url);
            $headers = array_change_key_case(array_map(function ($values) {
                return implode(', ', (array) $values);
            }, $response->headers()), CASE_LOWER);
            return static::$cache[$url] = ['status' => $response->status(), 'headers' => $headers];
        } catch (\Throwable $e) {
            return static::$cache[$url] = null;
        }
    }

    /**
     * A path of the admin pages, with the prefix of this site (ADMIN_ROUTE_PREFIX; empty,
     * the default of a new site from 7.2.1: none), from the address of the site:
     * "admin/auth/login", or "auth/login" without a prefix.
     */
    public static function adminPath(string $path): string
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $path = ltrim($path, '/');
        return $prefix === '' ? $path : $prefix . '/' . $path;
    }

    public static function clear(): void
    {
        static::$cache = [];
    }
}
