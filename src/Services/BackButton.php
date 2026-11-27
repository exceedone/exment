<?php

namespace Exceedone\Exment\Services;

use ExmentAdminCore\Admin\Facades\Admin;
use Illuminate\Http\Request;

/**
 * The "back" button at the left of the page title (ISS00363).
 *
 * The data show, create and edit pages had it first (#1908): "back to the list", to the list
 * the user came from. Every other admin page now has one too, with the same look, and its
 * destination is worked out here, on the server, for each request:
 *
 * (a) a page of a resource (create, {id}/edit, {id}) goes to the list of that resource. The
 *     data pages are drawn by CustomValueController, which knows the remembered list of the
 *     table (CustomTable::getGridUrl(true)), so they are left out here.
 * (b) a list or a screen under another one goes to the one above it (PARENTS): the settings of
 *     a table go to the table list, the screens of a plugin to the plugin list, ...
 * (c) any other page goes to the page before it, when that page is of this site and is not the
 *     same page.
 *
 * There is no button on the dashboard, on the lists of the menu (the data list, the table
 * list, the user list, ...), on the login pages, and on anything that is not a page: ajax,
 * json, api, the public form, requests other than GET, modals and frames.
 */
class BackButton
{
    /**
     * The endpoints that have a list per table: "<endpoint>/<table>".
     */
    public const TABLE_ENDPOINTS = [
        'data',
        'column',
        'form',
        'formpriority',
        'formpublic',
        'view',
        'relation',
        'copy',
        'operation',
        'notify',
    ];

    /**
     * (b) A screen under another one => the one above it. "{x}" is any one segment of the path.
     * Looked at before the rule (a) and before NO_BUTTON, so a fixed path here wins.
     */
    public const PARENTS = [
        // the settings of a table => the table list
        'column/{x}' => 'table',
        'form/{x}' => 'table',
        'formpriority/{x}' => 'table',
        'formpublic/{x}' => 'table',
        'view/{x}' => 'table',
        'relation/{x}' => 'table',
        'copy/{x}' => 'table',
        'operation/{x}' => 'table',
        'notify/{x}' => 'table',
        'data_maintenance/{x}' => 'table',
        // the screens of a workflow => the workflow list
        'workflow/{x}/notify' => 'workflow',
        'workflow/{x}/design' => 'workflow',
        'workflow/design/create' => 'workflow',
        // the screens of a plugin => the plugin list
        'plugin/edit_code/{x}' => 'plugin',
        // a dashboard is the top page: its own settings go back to it
        'dashboard/create' => '/',
        'dashboard/{x}/edit' => '/',
        // the other screens of a list
        'auth/menu_arrange' => 'auth/menu',
        'studio/screens/{x}/edit' => 'studio',
    ];

    /**
     * Pages with no button: the dashboard, and the lists of the menu. Any path of one segment
     * (table, loginuser, plugin, system, ...) is one of them as well.
     */
    public const NO_BUTTON = [
        'data/{x}',
        'dashboard/{x}',
        'plugins/{x}',
        'auth/menu',
        'auth/logs',
        'auth/setting',
        'auth/api_keys',
    ];

    /**
     * Paths that are not admin pages, or are pages before the login: no button under them.
     */
    public const NO_BUTTON_PREFIXES = [
        'auth/login',
        'auth/logout',
        'auth/forget',
        'auth/reset',
        'auth/change',
        'auth-2factor',
        'saml',
        'initialize',
        'install',
        'api',
        'webapi',
        'publicformapi',
        'oauth',
        'files',
        'tmpfiles',
        'favicon',
    ];

    /**
     * Query keys of a page drawn into a modal or a frame.
     */
    public const FRAME_KEYS = ['modal', 'modalframe', 'formframe', 'iframe'];

    /**
     * The destination of the button of this request, or null for no button.
     *
     * @param Request $request
     * @return string|null
     */
    public static function destinationForRequest(Request $request): ?string
    {
        if (!$request->isMethod('GET')) {
            return null;
        }
        // pjax is ajax as well, and is a page
        if ($request->ajax() && !$request->pjax()) {
            return null;
        }
        if ($request->expectsJson()) {
            return null;
        }
        foreach (static::FRAME_KEYS as $key) {
            if ($request->has($key)) {
                return null;
            }
        }
        if (is_null(Admin::user())) {
            return null;
        }

        $previous = $request->headers->get('referer');
        if (empty($previous) && $request->hasSession()) {
            $previous = $request->session()->previousUrl();
        }

        return static::destination(admin_exclusion_path($request->path()), $previous, $request->url());
    }

    /**
     * The destination of the button of a page, or null for no button.
     *
     * @param string $path the path of the page in the admin (without the admin prefix)
     * @param string|null $previous the url of the page before (the referer)
     * @param string|null $current the url of the page (without the query)
     * @return string|null
     */
    public static function destination(string $path, ?string $previous = null, ?string $current = null): ?string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }
        $segments = explode('/', $path);

        if (pathinfo($path, PATHINFO_EXTENSION) !== '') {
            return null;
        }
        foreach (static::NO_BUTTON_PREFIXES as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return null;
            }
        }

        // (b)
        foreach (static::PARENTS as $pattern => $parent) {
            if (static::match($segments, $pattern)) {
                return admin_url($parent);
            }
        }

        if (count($segments) === 1 || static::matchAny($segments, static::NO_BUTTON)) {
            return null;
        }

        // (a)
        $index = static::resourceIndex($segments);
        if ($index !== null) {
            // the data pages are drawn by CustomValueController, with the remembered list
            if (count($index) === 2 && $index[0] === 'data') {
                return null;
            }
            return admin_url(implode('/', $index));
        }

        // (c)
        return static::previous($previous, $current);
    }

    /**
     * Add the button to the page title.
     *
     * @param string $url
     * @param string $label
     * @return void
     */
    public static function append(string $url, string $label): void
    {
        Admin::script(static::script($url, $label));
    }

    /**
     * The script that puts the button at the left of the page title (the look of #1908).
     * The page title is made by the admin layout, which has no place for buttons. A pjax page
     * runs the script again on a new title; a title that has the button already is left alone.
     *
     * @param string $url
     * @param string $label
     * @return string
     */
    public static function script(string $url, string $label): string
    {
        $url = static::jsString($url);
        $label = static::jsString($label);
        return <<<EOT
(function () {
    var h1 = $('#pjax-container .content-header > h1').first();
    if (!h1.length || h1.find('.exm-back-to-list').length) {
        return;
    }
    var a = $('<a class="btn exm-back-to-list"><i class="fa fa-arrow-left"></i> </a>').attr('href', {$url});
    a.append(document.createTextNode({$label}));
    h1.addClass('exm-has-back-to-list').prepend(a);
})();
EOT;
    }

    /**
     * A string as a JavaScript literal, safe inside a script tag.
     *
     * @param string $value
     * @return string
     */
    public static function jsString(string $value): string
    {
        return strval(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    /**
     * The list of a page of a resource: <base>/create, <base>/{id}/edit, <base>/{id} (a number).
     *
     * @param array<int, string> $segments
     * @return array<int, string>|null the segments of the list
     */
    protected static function resourceIndex(array $segments): ?array
    {
        $count = count($segments);
        $last = $segments[$count - 1];

        $candidates = [];
        if ($last === 'create') {
            $candidates[] = array_slice($segments, 0, $count - 1);
        } elseif ($last === 'edit' && $count >= 3) {
            $candidates[] = array_slice($segments, 0, $count - 2);
        } elseif (ctype_digit($last)) {
            $candidates[] = array_slice($segments, 0, $count - 1);
        }

        foreach ($candidates as $base) {
            if (static::isResourceBase($base)) {
                return $base;
            }
        }
        return null;
    }

    /**
     * Whether the path is the list of a resource: one segment (table, plugin, loginuser, ...),
     * "<endpoint>/<table>", or one of the lists under another one.
     *
     * @param array<int, string> $segments
     * @return bool
     */
    protected static function isResourceBase(array $segments): bool
    {
        $count = count($segments);
        if ($count === 1) {
            return true;
        }
        if ($count === 2 && in_array($segments[0], static::TABLE_ENDPOINTS, true)) {
            return true;
        }
        return static::matchAny($segments, ['workflow/{x}/notify', 'auth/menu', 'auth/logs']);
    }

    /**
     * (c) The page before, when it is of this site and not this same page.
     *
     * @param string|null $previous
     * @param string|null $current
     * @return string|null
     */
    protected static function previous(?string $previous, ?string $current): ?string
    {
        if (empty($previous) || empty($current)) {
            return null;
        }
        $p = parse_url($previous);
        $c = parse_url($current);
        if (!is_array($p) || !is_array($c) || !isset($p['host'], $c['host'])) {
            return null;
        }
        $scheme = function ($u) {
            return strtolower(strval($u['scheme'] ?? ''));
        };
        $port = function ($u) use ($scheme) {
            return $u['port'] ?? ($scheme($u) === 'https' ? 443 : 80);
        };
        if (strtolower($p['host']) !== strtolower($c['host']) || $scheme($p) !== $scheme($c) || $port($p) !== $port($c)) {
            return null;
        }
        if (!in_array($scheme($p), ['http', 'https'], true)) {
            return null;
        }
        if (rtrim($p['path'] ?? '/', '/') === rtrim($c['path'] ?? '/', '/')) {
            return null;
        }
        return $previous;
    }

    /**
     * @param array<int, string> $segments
     * @param array<int, string> $patterns
     * @return bool
     */
    protected static function matchAny(array $segments, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (static::match($segments, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the path is the pattern ("{x}" is any one segment).
     *
     * @param array<int, string> $segments
     * @param string $pattern
     * @return bool
     */
    protected static function match(array $segments, string $pattern): bool
    {
        $parts = explode('/', $pattern);
        if (count($parts) !== count($segments)) {
            return false;
        }
        foreach ($parts as $i => $part) {
            if ($part !== '{x}' && $part !== $segments[$i]) {
                return false;
            }
        }
        return true;
    }
}
