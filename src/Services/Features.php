<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Illuminate\Http\Request;

/**
 * The features of the site that an administrator turns on (Admin setting →
 * Feature management).
 *
 * Exment is used by many sites, so a new feature is off until a system
 * administrator turns it on: updating the site does not change its pages or
 * its menu. The rules, from the top (a lower one never opens more than the
 * one above it):
 *
 * 1. the server: EXMENT_FEATURES_LOCKED=file_server,studio keeps features off
 *    whatever the administrators of the site choose (a SaaS)
 * 2. the site: the feature is used or not ("features" below). When it is not,
 *    its pages and its API answer "not found", even when the URL is opened
 *    directly, and its menus are hidden. Its data stays: turning it on again
 *    brings everything back. A new feature is off until it is turned on; only
 *    Exment's own pages for the system administrators (as Tuning) may be on
 *    from the start ("default"). A plugin cannot ask for it: it has "existing".
 * 3. who: all users, or the users of some role groups (none chosen: the
 *    system administrators only, who always may)
 * 4. the settings of the feature itself (as the permissions of the tables
 *    for Studio, or the permissions of a workspace)
 *
 * The plugins are on the same page, with what they had already (their
 * "active" flag and the plugin_access permission of the role groups), and the
 * menu flag kept here. A plugin may register a feature of its own
 * (Features::register()) and check it in its pages (Features::allowed()).
 *
 * The setting is the system setting "features" (json):
 * {"file_server": {"enabled": true, "role_groups": [1, 3], "menu": true},
 *  "plugin:<plugin name>": {"menu": false}, "_since": "<when the site got this page>"}
 * role_groups: null = all users, [] = the system administrators only.
 */
class Features
{
    public const SETTING = 'features';

    public const FILE_SERVER = 'file_server';
    public const STUDIO = 'studio';
    public const TUNING = 'tuning';
    public const API_KEYS = 'api_keys';
    public const MCP = 'mcp';
    public const SAFETY_CHECK = 'safety_check';

    /** The key of the menu flag of a plugin in the setting. */
    public const PLUGIN_PREFIX = 'plugin:';

    /** The header of the answers of deny() (Exment::error gives them as they are when they are thrown). */
    public const DENIED_HEADER = 'X-Exment-Feature-Denied';

    /** When the site got Feature management (the plugins installed before it keep their features). */
    public const SINCE = '_since';

    /**
     * The features a new site had when it was installed (written by the
     * migration on a new site only): they are not new to it, nothing is told
     * of them. A feature added by a later update is not in it.
     */
    public const KNOWN = '_known';

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
     * The plugins (per request).
     *
     * @var Plugin[]|null
     */
    protected static ?array $plugins = null;

    /**
     * The definitions (core() + the plugins'), kept until one more is registered.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected static ?array $definitions = null;

    /** Whether the classes of the plugin pages were made here (the routes cached). */
    protected static bool $pluginsBooted = false;

    /** The cache of what the plugins registered (the routes cached). */
    public const PLUGINS_CACHE_KEY = 'exment_features_plugins_';

    public const PLUGINS_CACHE_SECONDS = 3600;

    protected static ?bool $routesCachedForTests = null;

    /**
     * The features of Exment itself (the file server when Exment has it).
     *
     * - endpoints: the first part of the URL after the prefix of the admin
     *   pages (and of the API, after "api/" or "webapi/")
     * - menu: the system menu (admin_menu.menu_name) of the feature
     *
     * @return array<string, array<string, mixed>>
     */
    public static function core(): array
    {
        $core = [];
        if (class_exists('\Exceedone\Exment\Services\FileServer\Library')) {
            $core[static::FILE_SERVER] = static::definition([
                'label' => 'exment::exment.feature.features.file_server',
                'description' => 'exment::exment.feature.features.file_server_help',
                'endpoints' => ['file_server'],
                'menu' => 'file_server',
                'manual' => 'file_server',
            ]);
        }
        $core[static::STUDIO] = static::definition([
            'label' => 'exment::exment.feature.features.studio',
            'description' => 'exment::exment.feature.features.studio_help',
            // the phone app (/app, its manifest, service worker and code) is a part of Studio
            'endpoints' => ['studio', 'app'],
            'api' => ['studio'],
            'menu' => 'studio',
            'manual' => 'studio',
        ]);
        // the API keys of the users (Services\UserApiKeys, ISS00437): off until an administrator
        // turns it on and chooses the role groups whose users may make keys
        $core[static::API_KEYS] = static::definition([
            'label' => 'exment::exment.feature.features.api_keys',
            'description' => 'exment::exment.feature.features.api_keys_help',
            'endpoints' => ['auth/api_keys'],
        ]);
        // the MCP server (api/mcp, ISS00439): off until an administrator turns it on and
        // chooses the role groups whose users may use it from an AI
        $core[static::MCP] = static::definition([
            'label' => 'exment::exment.feature.features.mcp',
            'description' => 'exment::exment.feature.features.mcp_help',
            'api' => ['mcp'],
        ]);
        // the safety check: its page, the answer page (SafetyCheckAnswerController),
        // the automatic sending (exment:safetywatch) and its LINE answers. Every
        // site it was on before keeps it on (the migration keep_safety_check_feature);
        // a new site starts with it off. The LINE link stays: the notifications use LINE too.
        $core[static::SAFETY_CHECK] = static::definition([
            'label' => 'exment::exment.feature.features.safety_check',
            'description' => 'exment::exment.feature.features.safety_check_help',
            'endpoints' => ['safety_check'],
            'manual' => 'safety_check',
        ]);
        // the advice is about the server (php.ini, .env): a SaaS keeps it from the tenants
        // with EXMENT_FEATURES_LOCKED=tuning (ISS00428)
        $core[static::TUNING] = static::definition([
            // keys, translated when shown (Features::label / description)
            'label' => 'exment::exment.feature.features.tuning',
            'description' => 'exment::exment.feature.features.tuning_help',
            'endpoints' => ['tuning'],
            'menu' => 'tuning',
            'manual' => 'tuning',
            // who is not asked here: Auth\Permission keeps the page to the system administrators
            'who' => false,
            // it was there before Feature management: on until it is turned off
            'default' => true,
        ]);
        // maintenance mode (Services\MaintenanceMode): the system administrators only
        $core[MaintenanceMode::FEATURE] = static::definition([
            'label' => 'exment::exment.feature.features.maintenance_mode',
            'description' => 'exment::exment.feature.features.maintenance_mode_help',
            'endpoints' => ['maintenance'],
            'menu' => 'maintenance',
            'who' => false,
        ]);
        return $core;
    }

    /**
     * A feature of a plugin (call it when the plugin is made, as in its
     * constructor). The key starts with the uri of the plugin (the "uri" of
     * its config.json, as "exment_groupware"): "exment_groupware.mail".
     *
     * It is off until an administrator turns it on, but a feature that the
     * plugin had before (existing) stays on in the sites that had the plugin
     * before they got Feature management.
     *
     * @param array<string, mixed> $definition
     *   - label, description
     *   - endpoints: the pages, after the prefix of the admin pages ("plugins/exment_groupware/mail")
     *   - api: the same, of the API (after "api/")
     *   - menu: a system menu name (rare for a plugin)
     *   - existing: the plugin had it before (default false)
     *   - (not "default": a feature of a plugin starts off, or as it was with "existing")
     *   - who: whether "who may use it" is asked here (default true; false: all who may use the plugin)
     *   - requires: the key of another feature it needs (off when that one is)
     *   - plugin: the uri or the name of the plugin (default: the key before the first ".")
     *   - manual: its manual, a page of the Exment manual ("file_server", "file_server?id=share")
     *     or a full https:// URL (the plugin's own). The help button of its pages and the
     *     row of Feature management open it.
     *   - action: a button in its row of Feature management, ['label' => ..., 'url' => ...]: what
     *     the feature needs done before it can work (as "install the editor"). The URL is an
     *     http(s) URL or a path of the site; anything else drops the button. A plugin registers
     *     on every request, so it gives the button only while there is something to do.
     */
    public static function register(string $key, array $definition): void
    {
        $key = trim($key);
        if ($key === '' || str_starts_with($key, '_') || array_key_exists($key, static::core()) || str_starts_with($key, static::PLUGIN_PREFIX)) {
            return;
        }
        $definition['plugin'] = array_get($definition, 'plugin') ?? strtok($key, '.');
        // on from the start is for Exment's own pages only: a new feature of a plugin starts off
        unset($definition['default']);
        static::$registered[$key] = static::definition($definition);
        static::$definitions = null;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected static function definition(array $definition): array
    {
        $paths = function ($list) {
            return array_values(array_filter(array_map(function ($endpoint) {
                return trim(strval($endpoint), '/');
            }, (array)$list)));
        };
        $requires = array_get($definition, 'requires');
        return [
            'label' => strval(array_get($definition, 'label', '')),
            'description' => strval(array_get($definition, 'description', '')),
            'endpoints' => $paths(array_get($definition, 'endpoints', [])),
            'api' => $paths(array_get($definition, 'api', [])),
            'menu' => array_get($definition, 'menu'),
            'plugin' => array_get($definition, 'plugin'),
            'existing' => boolval(array_get($definition, 'existing', false)),
            'default' => boolval(array_get($definition, 'default', false)),
            'who' => boolval(array_get($definition, 'who', true)),
            'requires' => is_string($requires) && $requires !== '' ? $requires : null,
            'manual' => static::manualOf(array_get($definition, 'manual')),
            'action' => static::actionOf(array_get($definition, 'action')),
        ];
    }

    /**
     * An action of a definition as it may be kept: a label and an http(s) URL or a path of
     * the site (no "javascript:", no quotes). Null otherwise.
     *
     * @return array{label: string, url: string}|null
     */
    protected static function actionOf(mixed $action): ?array
    {
        if (!is_array($action)) {
            return null;
        }
        $label = trim(strval($action['label'] ?? ''));
        $url = trim(strval($action['url'] ?? ''));
        if ($label === '' || $url === '' || mb_strlen($label) > 100) {
            return null;
        }
        if (preg_match('#\Ahttps?://[^\s"\'<>]+\z#i', $url) !== 1 && preg_match('#\A/(?!/)[^\s"\'<>]*\z#', $url) !== 1) {
            return null;
        }
        return ['label' => $label, 'url' => $url];
    }

    /**
     * The button of a feature in Feature management, its label translated (null: none).
     *
     * @return array{label: string, url: string}|null
     */
    public static function action(string $key): ?array
    {
        $action = static::attribute($key, 'action');
        if (!is_array($action)) {
            return null;
        }
        return ['label' => static::translated(strval($action['label'])), 'url' => strval($action['url'])];
    }

    /**
     * A manual of a definition as it may be kept: a page of the Exment manual (letters,
     * numbers, "_", "-", "/", a query and an anchor), or an http(s) URL. Anything else
     * (as "javascript:") is dropped.
     */
    protected static function manualOf(mixed $manual): ?string
    {
        if (!is_string($manual)) {
            return null;
        }
        $manual = trim($manual);
        if (preg_match('#\Ahttps?://[^\s"\'<>]+\z#i', $manual) === 1) {
            return $manual;
        }
        if (preg_match('#\A[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*(\?[A-Za-z0-9_\-=&%]*)?(\#[A-Za-z0-9_\-%]*)?\z#', $manual) === 1) {
            return $manual;
        }
        return null;
    }

    /**
     * The URL of the manual of a feature (null: it has none).
     */
    public static function manualUrl(string $key): ?string
    {
        $manual = static::attribute($key, 'manual');
        if (!is_string($manual) || $manual === '') {
            return null;
        }
        return preg_match('#\Ahttps?://#i', $manual) === 1 ? $manual : getManualUrl($manual);
    }

    /**
     * The entries of the help button (as Define::HELP_URLS) for the pages of the features
     * that have a manual. They come before Define::HELP_URLS: the first that matches is used.
     *
     * @return array<int, array{uri: string, help_uri: string}>
     */
    public static function helpUrls(): array
    {
        $urls = [];
        foreach (static::definitions() as $definition) {
            $manual = $definition['manual'] ?? null;
            if (!is_string($manual) || $manual === '') {
                continue;
            }
            foreach ($definition['endpoints'] as $endpoint) {
                $urls[] = ['uri' => $endpoint, 'help_uri' => $manual];
            }
        }
        return $urls;
    }

    /**
     * All the features (Exment's and the plugins').
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        static::bootPlugins();
        return static::$definitions ??= static::core() + static::$registered;
    }

    /**
     * The plugins register their features when their classes are made: when
     * the routes are made (PluginServiceProvider::map). With the routes cached
     * (php artisan route:cache) they are not made there, so what they register
     * is kept in the cache, for the plugins as they are: the classes are made
     * once, not on each request.
     *
     * A feature not known is not stopped, so what is kept must be all: it is
     * kept only when the class of every active page plugin was made, it is
     * forgotten when a plugin is installed, changed or removed
     * (forgetPluginsCache), and it is kept for an hour at most. When the cache
     * cannot be read or written, the classes are made (as without the cache).
     */
    protected static function bootPlugins(): void
    {
        if (static::$pluginsBooted) {
            return;
        }
        static::$pluginsBooted = true;
        try {
            if (!static::routesAreCached() || !canConnection() || !hasTable(\Exceedone\Exment\Enums\SystemTableName::PLUGIN)) {
                return;
            }
        } catch (\Throwable $e) {
            \Log::warning('[Exment] the features of the plugins: ' . $e->getMessage());
            return;
        }

        $cacheKey = null;
        try {
            $cacheKey = static::pluginsCacheKey();
            $kept = \Cache::get($cacheKey);
            if (is_array($kept)) {
                static::$registered += $kept;
                static::$definitions = null;
                return;
            }
        } catch (\Throwable $e) {
            // the cache cannot be read: made as without the cache
            \Log::warning('[Exment] the cache of the features of the plugins: ' . $e->getMessage());
            $cacheKey = null;
        }

        try {
            $built = Plugin::getPluginPages()->count();
        } catch (\Throwable $e) {
            \Log::warning('[Exment] the features of the plugins: ' . $e->getMessage());
            return;
        }
        static::$definitions = null;
        if ($cacheKey === null || $built < static::activePagePluginCount()) {
            // a class could not be made (its files not there yet, an error): not kept
            return;
        }
        try {
            // all that is registered (also what was registered before in this
            // request, as by the scripts of the plugins in admin.bootstrap)
            \Cache::put($cacheKey, static::$registered, static::PLUGINS_CACHE_SECONDS);
        } catch (\Throwable $e) {
            \Log::warning('[Exment] the cache of the features of the plugins: ' . $e->getMessage());
        }
    }

    /**
     * The active plugins that have pages (their classes register the features).
     */
    protected static function activePagePluginCount(): int
    {
        return Plugin::query()->where('active_flg', true)->get()->filter(function ($plugin) {
            return $plugin->matchPluginType(\Exceedone\Exment\Enums\PluginType::PLUGIN_TYPE_PLUGIN_PAGE());
        })->count();
    }

    /**
     * Forget what the plugins registered (a plugin was installed, changed,
     * turned on or off, or removed): the next request makes the classes again.
     */
    public static function forgetPluginsCache(): void
    {
        try {
            \Cache::forever(static::pluginsGenerationKey(), uniqid('', true));
        } catch (\Throwable $e) {
            \Log::warning('[Exment] the cache of the features of the plugins: ' . $e->getMessage());
        }
        static::$pluginsBooted = false;
        static::$definitions = null;
    }

    /**
     * Whether the routes are cached (php artisan route:cache); a test may say.
     */
    protected static function routesAreCached(): bool
    {
        return static::$routesCachedForTests ?? app()->routesAreCached();
    }

    /**
     * For the tests: as if the routes were cached (true), or not (false),
     * or as they are (null).
     */
    public static function routesCachedForTests(?bool $cached): void
    {
        static::$routesCachedForTests = $cached;
    }

    /**
     * The key of the cache of what the plugins registered: it changes when a
     * plugin is added, removed, turned on or off, or changed, and when the
     * cache is forgotten (forgetPluginsCache). The database and the URL of the
     * site are in it, so sites that share a cache store (a SaaS) do not share
     * the key.
     */
    public static function pluginsCacheKey(): string
    {
        $sign = Plugin::query()->orderBy('id')->get()->map(function ($plugin) {
            $row = $plugin->getAttributes();
            return [$row['id'] ?? null, $row['plugin_name'] ?? null, $row['plugin_types'] ?? $row['plugin_type'] ?? null,
                $row['version'] ?? null, $row['active_flg'] ?? null, strval($row['updated_at'] ?? '')];
        })->toJson();
        $generation = strval(\Cache::get(static::pluginsGenerationKey(), ''));
        return static::PLUGINS_CACHE_KEY . md5(static::siteSign() . "\n" . $generation . "\n" . $sign);
    }

    /** The site (its database and URL), for the keys of a cache store shared by sites. */
    protected static function siteSign(): string
    {
        $connection = strval(config('database.default'));
        return implode('|', [$connection, strval(config('database.connections.' . $connection . '.database')), strval(config('app.url'))]);
    }

    protected static function pluginsGenerationKey(): string
    {
        return static::PLUGINS_CACHE_KEY . 'generation_' . md5(static::siteSign());
    }

    /**
     * An attribute of the definition of a feature (the keys have dots: no array_get).
     */
    protected static function attribute(string $key, string $name, mixed $default = null): mixed
    {
        $definition = static::definitions()[$key] ?? null;
        return is_array($definition) && array_key_exists($name, $definition) ? $definition[$name] : $default;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, static::definitions());
    }

    /**
     * The name of a feature, in the language of the page. A plugin may give a
     * key of its translations ("exment_x::feature.desk"): the plugin registers
     * before its translations and the language of the user are known, so the
     * key is translated here, when the page shows it.
     */
    public static function label(string $key): string
    {
        return static::translated(strval(static::attribute($key, 'label', ''))) ?: $key;
    }

    public static function description(string $key): string
    {
        return static::translated(strval(static::attribute($key, 'description', '')));
    }

    protected static function translated(string $text): string
    {
        if (str_contains($text, '::') && preg_match('/\A[\w.\-]+::[\w.\-]+\z/', $text) === 1 && \Lang::has($text)) {
            return strval(trans($text));
        }
        return $text;
    }

    /**
     * The plugin of a feature of a plugin (null: a feature of Exment, or the plugin is not there).
     */
    public static function pluginOf(string $key): ?Plugin
    {
        $name = static::attribute($key, 'plugin');
        if (!is_string($name) || $name === '') {
            return null;
        }
        static::$plugins ??= Plugin::query()->get()->all();
        foreach (static::$plugins as $plugin) {
            if ($plugin->plugin_name === $name || $plugin->getOption('uri') === $name || pascalize(strval($plugin->plugin_name)) === pascalize($name)) {
                return $plugin;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- the setting

    /**
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $value = System::features();
        // json_decode_ex may give objects
        $value = json_decode(strval(json_encode($value ?? [])), true);
        return is_array($value) ? $value : [];
    }

    /**
     * Whether an administrator has not decided on the feature yet (it shows as new).
     */
    public static function isNew(string $key): bool
    {
        return !array_key_exists($key, static::settings());
    }

    /**
     * The setting of a feature (a feature not set is off, but for the
     * features plugins had before the site got this page).
     *
     * @return array{enabled: bool, role_groups: int[]|null, menu: bool}
     */
    public static function setting(string $key): array
    {
        $settings = static::settings();
        if (!array_key_exists($key, $settings) || !is_array($settings[$key])) {
            return ['enabled' => static::enabledByDefault($key, $settings), 'role_groups' => null, 'menu' => true];
        }
        $row = $settings[$key];
        $groups = array_get($row, 'role_groups');
        return [
            'enabled' => boolval(array_get($row, 'enabled', false)),
            // null: all users; []: the system administrators only
            'role_groups' => is_array($groups) ? array_values(array_unique(array_map('intval', $groups))) : null,
            'menu' => boolval(array_get($row, 'menu', true)),
        ];
    }

    /**
     * A feature not set yet: on only when a plugin had it before (existing)
     * and the plugin was there before the site got Feature management
     * ("_since", written by the migration on every site, new ones too: on a
     * new site every plugin comes after it). Without "_since" (the setting
     * written by hand, or restored from an old backup) the site is taken as
     * one that had its plugins before: their features stay on, so that
     * nothing a site used stops by itself.
     *
     * @param array<string, mixed> $settings
     */
    protected static function enabledByDefault(string $key, array $settings): bool
    {
        $definition = static::definitions()[$key] ?? null;
        if (is_array($definition) && $definition['default']) {
            // a feature that is on until it is turned off (as Tuning)
            return true;
        }
        if (!is_array($definition) || !$definition['existing']) {
            return false;
        }
        $since = array_get($settings, static::SINCE);
        if (!is_string($since) || $since === '') {
            // not known: the safe side (what the plugin had stays on)
            return true;
        }
        $plugin = static::pluginOf($key);
        if (!isset($plugin) || !isset($plugin->created_at)) {
            return true;
        }
        try {
            return \Carbon\Carbon::parse(strval($plugin->created_at))->lt(\Carbon\Carbon::parse($since));
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Save the settings (of the features given; the others are kept).
     *
     * @param array<string, array<string, mixed>> $values role_groups: null (all users) or a list (none: the system administrators only)
     */
    public static function save(array $values): void
    {
        $settings = static::settings();
        foreach ($values as $key => $row) {
            $key = strval($key);
            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }
            if (str_starts_with($key, static::PLUGIN_PREFIX)) {
                $settings[$key] = ['menu' => boolval(array_get($row, 'menu', true))];
                continue;
            }
            $groups = array_get($row, 'role_groups');
            $settings[$key] = [
                'enabled' => boolval(array_get($row, 'enabled', false)),
                'role_groups' => is_array($groups) ? array_values(array_unique(array_filter(array_map('intval', $groups)))) : null,
                'menu' => boolval(array_get($row, 'menu', true)),
            ];
        }
        System::features($settings);
        static::$userRoleGroups = [];

        // maintenance mode turned off: a maintenance of the site ends now (and does not come back)
        $maintenance = $values[MaintenanceMode::FEATURE] ?? null;
        if (is_array($maintenance) && !boolval(array_get($maintenance, 'enabled', false))) {
            MaintenanceMode::featureTurnedOff(\Exment::user());
        }
    }

    // ---------------------------------------------------------------- the checks

    /**
     * The features the server keeps off (EXMENT_FEATURES_LOCKED).
     *
     * @return string[]
     */
    public static function lockedKeys(): array
    {
        $value = config('exment.features_locked', '');
        $list = is_array($value) ? $value : explode(',', strval($value));
        return array_values(array_filter(array_map('trim', array_map('strval', $list))));
    }

    public static function locked(string $key): bool
    {
        return in_array($key, static::lockedKeys(), true);
    }

    /**
     * Whether the feature is used on the site (and the server allows it, and
     * the feature it needs is used).
     */
    public static function enabled(string $key, int $depth = 0): bool
    {
        if ($depth > 5 || !static::exists($key) || static::locked($key)) {
            return false;
        }
        if (!static::setting($key)['enabled']) {
            return false;
        }
        $requires = static::attribute($key, 'requires');
        return !isset($requires) || static::enabled($requires, $depth + 1);
    }

    /**
     * Whether the user (the login user by default) may use the feature: it is
     * used, and the user is a system administrator, or it is for all users,
     * or the user is in one of its role groups.
     *
     * @param mixed $user a login user (null: the login user)
     */
    public static function allowed(string $key, $user = null, int $depth = 0): bool
    {
        if (!static::enabled($key)) {
            return false;
        }
        $requires = static::attribute($key, 'requires');
        if (isset($requires) && ($depth > 5 || !static::allowed($requires, $user, $depth + 1))) {
            return false;
        }
        $groups = static::setting($key)['role_groups'];
        if ($groups === null || !static::attribute($key, 'who', true)) {
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
     * Why a feature is not used (for the page): locked, off, requires (the
     * feature it needs is off), or null (it is used).
     */
    public static function reason(string $key): ?string
    {
        if (static::locked($key)) {
            return 'locked';
        }
        if (!static::setting($key)['enabled']) {
            return 'off';
        }
        if (!static::enabled($key)) {
            return 'requires';
        }
        return null;
    }

    /**
     * The answer to a request of a feature the user may not use: 404 when
     * it is not used on the site (a system administrator gets the link to
     * Feature management), 403 when the user may not use it; JSON for the
     * ajax and API requests. The middleware answers so; a plugin may answer
     * the same from its own pages: return Features::deny('exment_x.mail');
     * It may also be thrown (throw new HttpResponseException(Features::deny(...))):
     * Exment::error gives it as it is. (abort(404) in an admin page answers
     * 200 with the error page.)
     */
    public static function deny(string $key, ?Request $request = null): \Symfony\Component\HttpFoundation\Response
    {
        $request = $request ?? request();
        $used = static::enabled($key);
        $status = $used ? 403 : 404;
        $message = strval(exmtrans($used ? 'feature.message_not_allowed' : 'feature.message_not_used'));
        if ($request->expectsJson() || $request->ajax() || $request->is(trim(strval(config('admin.route.prefix')) . '/api', '/') . '/*')) {
            return response()->json(['result' => false, 'message' => $message, 'toastr' => $message], $status, [static::DENIED_HEADER => $key]);
        }

        $user = \Exment::user();
        $admin = isset($user) && $user->hasPermission(Permission::SYSTEM);
        $data = [
            'message' => $message,
            // the others: whom to ask; a system administrator: where to turn it on
            'note' => $admin ? null : strval(exmtrans('feature.message_not_used_user')),
            'feature_url' => !$used && $admin ? admin_url('feature') : null,
            'home_url' => admin_url(''),
        ];
        // in the page of the admin settings (its menu, its header), as the other errors
        try {
            $content = new \ExmentAdminCore\Admin\Layout\Content();
            $content->header(static::label($key))->row(view('exment::feature.denied', $data)->render());
            return response($content->render(), $status, [static::DENIED_HEADER => $key]);
        } catch (\Throwable $e) {
            // without the admin page (as out of a request of a page): the view alone
            return response(view('exment::feature.denied', $data)->render(), $status, ['Content-Type' => 'text/html; charset=UTF-8', static::DENIED_HEADER => $key]);
        }
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
            static::$userRoleGroups[$userId] = collect($groups)->pluck('id')->map(function ($id) {
                return intval($id);
            })->all();
        }
        return static::$userRoleGroups[$userId];
    }

    /**
     * The feature of a request (null: none). The prefix of the admin pages,
     * and "api/" or "webapi/" for the API, are taken off the path first.
     */
    public static function forRequest(Request $request): ?string
    {
        // the routes match the decoded path (/admin/%73tudio is /admin/studio)
        $path = trim(rawurldecode($request->decodedPath()), '/');
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
     * The longest endpoint wins (a feature inside a plugin before the whole plugin).
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
        $found = $api ? static::match($path, 'api') : null;
        // a path of the API that no "api" has: the pages ("endpoints"; a plugin
        // may have given only them, and its API is under the same path)
        return $found ?? static::match($path, 'endpoints');
    }

    /**
     * The feature whose endpoints (of the kind) the path is in; the longest one wins.
     */
    protected static function match(string $path, string $kind): ?string
    {
        $found = null;
        $length = -1;
        foreach (static::definitions() as $key => $definition) {
            $endpoints = (array)$definition[$kind];
            foreach ($endpoints as $endpoint) {
                if (($path === $endpoint || str_starts_with($path, $endpoint . '/') || str_starts_with($path, $endpoint . '.')) && strlen($endpoint) > $length) {
                    $found = strval($key);
                    $length = strlen($endpoint);
                }
            }
        }
        return $found;
    }

    /**
     * Whether a menu of this URI (of any kind: system, plugin, custom) is
     * hidden, because the URI is a page of a feature the user may not use.
     * The menu then goes as its pages do.
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
     * The feature of a system menu (admin_menu.menu_name), when it has one.
     */
    public static function forSystemMenu(?string $menuName): ?string
    {
        foreach (static::definitions() as $key => $definition) {
            if (isset($menuName) && $definition['menu'] === $menuName) {
                return strval($key);
            }
        }
        return null;
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
        static::$plugins = null;
        static::$definitions = null;
        static::$pluginsBooted = false;
    }
}
