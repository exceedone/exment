<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Illuminate\Http\Request;

/**
 * The features of the site that an administrator turns on (Admin setting →
 * Feature management).
 *
 * Exment is used by many sites, so a new feature is off until a system
 * administrator turns it on: updating the site does not change its pages or
 * its menu. For each feature they decide:
 *
 * - whether it is used: when it is not, its pages and its API answer "not
 *   found", even when the URL is opened directly, and its menu is hidden.
 *   Its data stays: turning it on again brings everything back.
 * - who may use it: all users, or the users of some role groups (the system
 *   administrators always may).
 * - whether its menu is shown.
 *
 * The plugins are on the same page, with what they had already (their
 * "active" flag and the plugin_access permission of the role groups), and the
 * menu flag kept here. A plugin may register a feature of its own
 * (Features::register()) and check it in its pages (Features::allowed()).
 *
 * The setting is the system setting "features" (json):
 * {"file_server": {"enabled": true, "role_groups": [1, 3], "menu": true},
 *  "plugin:<plugin name>": {"menu": false}}
 */
class Features
{
    public const SETTING = 'features';

    public const FILE_SERVER = 'file_server';
    public const STUDIO = 'studio';

    /** The key of the menu flag of a plugin in the setting. */
    public const PLUGIN_PREFIX = 'plugin:';

    /**
     * The features of plugins (Features::register()).
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $registered = [];

    /**
     * The role groups of the users (per request).
     *
     * @var array<int, int[]>
     */
    protected static array $userRoleGroups = [];

    /**
     * The features of Exment itself.
     *
     * - endpoints: the first part of the URL after the prefix of the admin
     *   pages (and of the API, after "api/" or "webapi/")
     * - menu: the system menu (admin_menu.menu_name) of the feature
     *
     * @return array<string, array{label: string, description: string, endpoints: string[], api: string[], menu: ?string, plugin: ?string}>
     */
    public static function core(): array
    {
        return [
            static::FILE_SERVER => [
                'label' => strval(exmtrans('feature.features.file_server')),
                'description' => strval(exmtrans('feature.features.file_server_help')),
                'endpoints' => ['file_server'],
                'api' => [],
                'menu' => 'file_server',
                'plugin' => null,
            ],
            static::STUDIO => [
                'label' => strval(exmtrans('feature.features.studio')),
                'description' => strval(exmtrans('feature.features.studio_help')),
                // the phone app (/app, its manifest, service worker and code) is a part of Studio
                'endpoints' => ['studio', 'app'],
                'api' => ['studio'],
                'menu' => 'studio',
                'plugin' => null,
            ],
        ];
    }

    /**
     * A feature of a plugin (call it when the plugin is made, as in its
     * constructor). The key should start with the name of the plugin, as
     * "exment_groupware.mail". It is off until an administrator turns it on.
     *
     * @param array<string, mixed> $definition label, description, endpoints (of the admin pages, as "plugins/exment_groupware/mail"), api (the same, of the API), menu
     */
    public static function register(string $key, array $definition): void
    {
        $key = trim($key);
        if ($key === '' || array_key_exists($key, static::core()) || str_starts_with($key, static::PLUGIN_PREFIX)) {
            return;
        }
        static::$registered[$key] = [
            'label' => strval(array_get($definition, 'label', $key)),
            'description' => strval(array_get($definition, 'description', '')),
            'endpoints' => array_values(array_filter(array_map(function ($endpoint) {
                return trim(strval($endpoint), '/');
            }, (array)array_get($definition, 'endpoints', [])))),
            // the API of the plugin (after "api/", as "plugins/exment_groupware/mail")
            'api' => array_values(array_filter(array_map(function ($endpoint) {
                return trim(strval($endpoint), '/');
            }, (array)array_get($definition, 'api', [])))),
            'menu' => array_get($definition, 'menu'),
            'plugin' => array_get($definition, 'plugin'),
        ];
    }

    /**
     * All the features (Exment's and the plugins').
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return static::core() + static::$registered;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, static::definitions());
    }

    // ---------------------------------------------------------------- the setting

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function settings(): array
    {
        $value = System::features();
        // json_decode_ex may give objects
        $value = json_decode(strval(json_encode($value ?? [])), true);
        return is_array($value) ? $value : [];
    }

    /**
     * The setting of a feature (a feature not set is off).
     *
     * @return array{enabled: bool, role_groups: int[]|null, menu: bool}
     */
    public static function setting(string $key): array
    {
        $row = (array)array_get(static::settings(), $key, []);
        $groups = array_get($row, 'role_groups');
        return [
            'enabled' => boolval(array_get($row, 'enabled', false)),
            'role_groups' => is_array($groups) && count($groups) > 0 ? array_values(array_map('intval', $groups)) : null,
            'menu' => boolval(array_get($row, 'menu', true)),
        ];
    }

    /**
     * Save the settings (of the features given; the others are kept).
     *
     * @param array<string, array<string, mixed>> $values
     */
    public static function save(array $values): void
    {
        $settings = static::settings();
        foreach ($values as $key => $row) {
            $key = strval($key);
            if (str_starts_with($key, static::PLUGIN_PREFIX)) {
                $settings[$key] = ['menu' => boolval(array_get($row, 'menu', true))];
                continue;
            }
            $groups = array_values(array_unique(array_filter(array_map('intval', (array)array_get($row, 'role_groups', [])))));
            $settings[$key] = [
                'enabled' => boolval(array_get($row, 'enabled', false)),
                'role_groups' => count($groups) > 0 ? $groups : null,
                'menu' => boolval(array_get($row, 'menu', true)),
            ];
        }
        System::features($settings);
        static::$userRoleGroups = [];
    }

    // ---------------------------------------------------------------- the checks

    /**
     * Whether the feature is used on the site.
     */
    public static function enabled(string $key): bool
    {
        if (!static::exists($key)) {
            return false;
        }
        return static::setting($key)['enabled'];
    }

    /**
     * Whether the user (the login user by default) may use the feature: it is
     * used, and the user is a system administrator or in one of its role
     * groups (or it is for all users).
     *
     * @param mixed $user a login user (null: the login user)
     */
    public static function allowed(string $key, $user = null): bool
    {
        if (!static::enabled($key)) {
            return false;
        }
        $groups = static::setting($key)['role_groups'];
        if ($groups === null) {
            return true;
        }
        $user = $user ?? \Exment::user();
        if (!isset($user)) {
            return false;
        }
        if ($user->hasPermission(Permission::SYSTEM)) {
            return true;
        }
        return count(array_intersect($groups, static::roleGroupIds($user))) > 0;
    }

    /**
     * The role groups of a user (as a user, or by an organization).
     *
     * @param mixed $user a login user
     * @return int[]
     */
    public static function roleGroupIds($user): array
    {
        $userId = intval($user->getUserId());
        if (!array_key_exists($userId, static::$userRoleGroups)) {
            $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ALL);
            $groups = RoleGroup::getHasPermissionRoleGroup($userId, $user->getOrganizationIdsForQuery($enum));
            static::$userRoleGroups[$userId] = collect($groups)->pluck('id')->map('intval')->all();
        }
        return static::$userRoleGroups[$userId];
    }

    /**
     * The feature of a request (null: none). The prefix of the admin pages,
     * and "api/" or "webapi/" for the API, are taken off the path first.
     */
    public static function forRequest(Request $request): ?string
    {
        $path = trim($request->path(), '/');
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        if ($prefix !== '') {
            if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                return null;
            }
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }
        $api = false;
        foreach (['api/', 'webapi/'] as $segment) {
            if (str_starts_with($path, $segment)) {
                $path = substr($path, strlen($segment));
                $api = true;
                break;
            }
        }
        return static::forPath($path, $api);
    }

    /**
     * The feature of a path after the prefix of the admin pages (null: none).
     *
     * @param bool $api the path of the API (after "api/" or "webapi/")
     */
    public static function forPath(string $path, bool $api = false): ?string
    {
        // a menu may have a query (data/x?view=...)
        $path = trim(strval(strtok($path, '?')), '/');
        if ($path === '') {
            return null;
        }
        foreach (static::definitions() as $key => $definition) {
            $endpoints = $api ? (array)$definition['api'] : (array)$definition['endpoints'];
            foreach ($endpoints as $endpoint) {
                if ($path === $endpoint || str_starts_with($path, $endpoint . '/') || str_starts_with($path, $endpoint . '.')) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * Whether a menu of this URI (of any kind: system, plugin, custom) is
     * hidden, because the URI is a page of a feature the user may not use.
     * The menu then goes as its pages do (they answer "not found").
     */
    public static function hidesMenuUri(?string $uri): bool
    {
        if (!isset($uri) || $uri === '' || preg_match('#\A[a-z]+://#i', $uri) === 1) {
            return false;
        }
        $feature = static::forPath($uri);
        return $feature !== null && !static::allowed($feature);
    }

    /**
     * Whether a system menu (admin_menu.menu_name) is hidden: its feature is
     * not used, the user may not use it, or its menu is off.
     */
    public static function hidesSystemMenu(?string $menuName): bool
    {
        if (!isset($menuName)) {
            return false;
        }
        foreach (static::definitions() as $key => $definition) {
            if ($definition['menu'] === $menuName) {
                return !static::allowed($key) || !static::setting($key)['menu'];
            }
        }
        return false;
    }

    /**
     * Whether the menu of a plugin is hidden (its flag on this page; shown by default).
     */
    public static function hidesPluginMenu(?string $pluginName): bool
    {
        if (!isset($pluginName)) {
            return false;
        }
        $row = (array)array_get(static::settings(), static::PLUGIN_PREFIX . $pluginName, []);
        return !boolval(array_get($row, 'menu', true));
    }

    /**
     * For the tests.
     */
    public static function reset(): void
    {
        static::$registered = [];
        static::$userRoleGroups = [];
    }
}
