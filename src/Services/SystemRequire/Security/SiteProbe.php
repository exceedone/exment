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

    public static function base(): string
    {
        return rtrim(strval(config('app.url')) ?: url('/'), '/');
    }

    /**
     * @return array{status: int, headers: array<string, string>}|null
     */
    public static function get(string $path): ?array
    {
        $url = static::base() . '/' . ltrim($path, '/');
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

    public static function clear(): void
    {
        static::$cache = [];
    }
}
