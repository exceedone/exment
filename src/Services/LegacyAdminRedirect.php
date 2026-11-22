<?php

namespace Exceedone\Exment\Services;

/**
 * A site without the admin prefix (ADMIN_ROUTE_PREFIX empty, the default of
 * a new install from 7.2.1) that used "/admin" before: an old link
 * "/admin/<path>" goes to "/<path>" of the same site.
 *
 * Only for GET (and HEAD), only on a site whose prefix is empty, and only the
 * first "admin" segment is taken off. The target is a path of this site:
 * "//host", "\host" or a scheme in the rest cannot lead off the site.
 */
class LegacyAdminRedirect
{
    public const SEGMENT = 'admin';

    /**
     * The value typed for the admin prefix (the setup page), as it goes to
     * ADMIN_ROUTE_PREFIX: slashes and spaces at both ends are taken off; empty
     * means no prefix. Only letters, digits, "-" and "_" (one path segment), or
     * null when it has anything else.
     */
    public static function normalizePrefix(?string $value): ?string
    {
        $value = trim(trim(strval($value)), '/');
        if ($value === '') {
            return '';
        }
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) ? $value : null;
    }

    /**
     * Whether the site has no admin prefix, so the old "/admin" links are redirected.
     */
    public static function enabled(): bool
    {
        return trim(strval(config('admin.route.prefix')), '/') === '';
    }

    /**
     * The path to go to (from the site root, starting with "/"), or null when
     * the path does not start with the segment "admin".
     *
     * @param string $path the request path without the leading "/", e.g. "admin/data/x"
     */
    public static function target(string $path, ?string $query = null): ?string
    {
        $path = ltrim($path, '/');
        if ($path !== static::SEGMENT && strpos($path, static::SEGMENT . '/') !== 0) {
            return null;
        }
        $rest = substr($path, strlen(static::SEGMENT));
        // never off the site: no "//host", no "\host", no "scheme:"
        $rest = str_replace(chr(92), '/', $rest);
        $rest = ltrim($rest, '/');
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rest)) {
            $rest = '';
        }
        $target = '/' . $rest;
        if (!is_nullorempty($query)) {
            $target .= '?' . $query;
        }
        return $target;
    }
}
