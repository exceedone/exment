<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Illuminate\Support\Facades\File;

/**
 * The features that install a set of tables (a "pack"): a row of Feature management whose
 * definition has a "pack", a folder of templates/ (Features::core(): 'pack' => 'sales_pack').
 * Turning the feature on (Feature management, or the checkbox of /initialize) installs the
 * pack: its tables, columns, forms, views, dashboards, menus, notifies and role groups.
 *
 * The install may run any number of times and changes only what is missing:
 *
 * - a table, column, form, view, dashboard, menu, relation or role group the site has
 *   already (by its name, or the suuid of the template) is left as it is, with what the
 *   users changed in it; only what is not there is made
 * - the labels of a table that is there already, and a notify that is there already, are
 *   left as they are too (the importer would set them again)
 * - a role group that is there already gets the permissions of the tables made now (only
 *   those: its other permissions stay as they are)
 * - no data is imported (the data/ of a template is for the template page only)
 *
 * Turning the feature off removes nothing: the tables and their data stay, as on the other
 * rows of Feature management.
 *
 * The packs of the other areas (assets, HR, general affairs, expenses) are rows of the same
 * kind: a definition with its "pack", its label and help in the 7 languages, and the folder.
 */
class FeaturePacks
{
    /**
     * The features that have a pack.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (Features::definitions() as $key => $definition) {
            if (is_string($definition['pack'] ?? null) && static::exists(strval($key))) {
                $keys[] = strval($key);
            }
        }
        return $keys;
    }

    /**
     * Whether the feature has a pack, and its folder is there.
     */
    public static function exists(string $key): bool
    {
        $path = static::path($key);
        return isset($path) && File::exists("$path/config.json");
    }

    /**
     * The folder of the pack of a feature (null: it has none).
     */
    public static function path(string $key): ?string
    {
        $pack = Features::pack($key);
        return isset($pack) ? exment_package_path("templates/$pack") : null;
    }

    /**
     * The pack of a feature in the language of the page (with the names in the other languages).
     *
     * @return array<string, mixed>
     */
    public static function config(string $key): array
    {
        $path = static::path($key);
        if (!isset($path) || !File::exists("$path/config.json")) {
            throw new \RuntimeException("The feature $key has no pack.");
        }
        $json = (new TemplateImporter())->getMergeJson(File::get("$path/config.json"), ['basePath' => $path]);
        if (!is_array($json)) {
            throw new \RuntimeException("The pack of the feature $key cannot be read.");
        }
        return $json;
    }

    /**
     * Turn the feature on (its other settings are kept) and install its pack.
     */
    public static function turnOn(string $key): void
    {
        if (!static::exists($key) || Features::locked($key)) {
            return;
        }
        $setting = Features::setting($key);
        Features::save([$key => ['enabled' => true, 'role_groups' => $setting['role_groups'], 'menu' => $setting['menu']]]);
        System::clearCache();
        static::install($key);
    }

    /**
     * Install the pack of a feature: what the site does not have yet (see the class).
     *
     * It makes tables (DDL), so it runs outside of a transaction: call it after the
     * transaction of the settings is committed.
     */
    public static function install(string $key): void
    {
        $json = static::config($key);
        $tableNames = array_values(array_filter(array_map(function ($table) {
            return is_array($table) ? array_get($table, 'table_name') : null;
        }, (array) array_get($json, 'custom_tables', []))));
        $existingTables = CustomTable::query()->whereIn('table_name', $tableNames)->pluck('table_name')->map('strval')->all();

        foreach ((array) array_get($json, 'custom_tables', []) as $index => $table) {
            // the labels of a table that is there are the site's (the importer would delete and make them again)
            if (is_array($table) && in_array(array_get($table, 'table_name'), $existingTables, true)) {
                unset($json['custom_tables'][$index]['custom_column_multisettings']);
            }
        }

        // a notify that is there is the site's (the importer would write it again)
        $json['notifies'] = array_values(array_filter((array) array_get($json, 'notifies', []), function ($notify) {
            $suuid = is_array($notify) ? array_get($notify, 'suuid') : null;
            return is_array($notify) && (is_nullorempty($suuid) || !Notify::query()->where('suuid', $suuid)->exists());
        }));

        // the role groups that are there: they get the permissions of the tables made now (below)
        $roles = array_merge((array) array_get($json, 'roles', []), (array) array_get($json, 'role_groups', []));
        $existingGroups = RoleGroup::query()->whereIn('role_group_name', array_filter(array_column($roles, 'role_group_name')))
            ->get()->keyBy('role_group_name');

        // the menus that are there (a menu made now is placed below)
        $menusBefore = Menu::query()->whereIn('menu_name', array_filter(array_column((array) array_get($json, 'admin_menu', []), 'menu_name')))
            ->get(['menu_type', 'menu_name'])->map(function ($menu) {
                return $menu->menu_type . ':' . $menu->menu_name;
            })->all();

        // true: what is there is left as it is (only what is missing is made)
        // (from the container: a test may give one that fails)
        app(TemplateImporter::class)->import($json, false, true);

        static::placeNewMenus($json, $menusBefore);
        static::refreshDashboardLinks($json);

        // an install stopped on the way (an error, a timeout) may have left a view or a dashboard
        // of the pack without its parts: they are made again
        static::repairParts($json);

        $newTables = array_values(array_diff($tableNames, $existingTables));
        if (count($newTables) > 0 && $existingGroups->isNotEmpty()) {
            static::addPermissions($roles, $existingGroups, $newTables);
        }

        // all done: the pack is installed (Feature management does not install it again by itself)
        static::markInstalled($key);
        System::clearCache();
    }

    /**
     * A role group that was there gets the permissions of the tables made now (only those).
     *
     * @param array<int, mixed> $roles the role groups of the pack
     * @param \Illuminate\Support\Collection<string, RoleGroup> $existingGroups
     * @param string[] $newTables
     */
    protected static function addPermissions(array $roles, $existingGroups, array $newTables): void
    {
        \ExmentDB::transaction(function () use ($roles, $existingGroups, $newTables) {
            foreach ($roles as $role) {
                $group = $existingGroups->get(array_get($role, 'role_group_name'));
                if (!isset($group)) {
                    continue;
                }
                foreach ((array) array_get($role, 'role_group_permissions', []) as $permission) {
                    if (is_array($permission) && in_array(array_get($permission, 'role_group_target_name'), $newTables, true)) {
                        RoleGroupPermission::importTemplate($permission, true, ['parent' => $group]);
                    }
                }
            }
        });
    }

    /**
     * A menu of the pack that links to a dashboard of the pack (options.menu_target_dashboard_name)
     * and that is there: its link follows the dashboard when the dashboard was made again (a new
     * suuid). A link the site changed to something else than a dashboard stays.
     *
     * @param array<string, mixed> $json the pack
     */
    protected static function refreshDashboardLinks(array $json): void
    {
        foreach ((array) array_get($json, 'admin_menu', []) as $item) {
            $name = is_array($item) ? array_get($item, 'options.menu_target_dashboard_name') : null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $dashboard = Dashboard::query()->where('dashboard_name', $name)->first();
            $menu = Menu::query()->where('menu_type', array_get($item, 'menu_type'))->where('menu_name', array_get($item, 'menu_name'))->first();
            if (!isset($dashboard) || !isset($menu)) {
                continue;
            }
            $uri = '?dashboard=' . $dashboard->suuid;
            $current = strval($menu->uri);
            if ($current !== $uri && ($current === '' || str_starts_with($current, '?dashboard='))) {
                $menu->uri = $uri;
                $menu->save();
            }
        }
    }

    /**
     * The setting of when the packs were installed: {"<feature>": "<date and time>"}, kept in
     * the setting of Feature management (under "_packs", which the page does not write).
     */
    public const INSTALLED = '_packs';

    /**
     * When the pack of a feature was installed completely (null: not yet, or an install stopped
     * on the way).
     */
    public static function installedAt(string $key): ?string
    {
        $installed = array_get(Features::settings(), static::INSTALLED);
        $at = is_array($installed) ? ($installed[$key] ?? null) : null;
        return is_string($at) && $at !== '' ? $at : null;
    }

    /**
     * Write that the pack of a feature is installed completely.
     */
    public static function markInstalled(string $key): void
    {
        $settings = Features::settings();
        $installed = array_get($settings, static::INSTALLED);
        $installed = is_array($installed) ? $installed : [];
        $installed[$key] = now()->toDateTimeString();
        $settings[static::INSTALLED] = $installed;
        System::features($settings);
        System::clearCache();
    }

    /**
     * A menu made now that is the first of its group in the pack (as the link to the sales
     * dashboard) is first in the group of the site too: a group that was there (a site that
     * imported the pack before) has its items from 1, so they are moved down by one (their order
     * among them stays). A menu of order 0 comes last in Exment, so 1 is the first place.
     *
     * @param array<string, mixed> $json the pack
     * @param string[] $menusBefore "menu_type:menu_name" of the menus that were there
     */
    protected static function placeNewMenus(array $json, array $menusBefore): void
    {
        $menus = array_values(array_filter((array) array_get($json, 'admin_menu', []), 'is_array'));
        foreach ($menus as $item) {
            $parentName = array_get($item, 'parent_name');
            $key = array_get($item, 'menu_type') . ':' . array_get($item, 'menu_name');
            if (is_nullorempty($parentName) || in_array($key, $menusBefore, true)) {
                continue;
            }
            $orders = array_map('intval', array_column(array_filter($menus, function ($other) use ($parentName, $item) {
                return array_get($other, 'parent_name') === $parentName && array_get($other, 'menu_name') !== array_get($item, 'menu_name');
            }), 'order'));
            $order = intval(array_get($item, 'order'));
            if ($order < 1 || (count($orders) > 0 && $order >= min($orders))) {
                continue;
            }
            $menu = Menu::query()->where('menu_type', array_get($item, 'menu_type'))->where('menu_name', array_get($item, 'menu_name'))->first();
            if (!isset($menu) || intval($menu->parent_id) === 0) {
                continue;
            }
            $siblings = Menu::query()->where('parent_id', $menu->parent_id)->where('id', '<>', $menu->id)->where('order', '>', 0);
            $first = (clone $siblings)->min('order');
            if (!isset($first) || intval($first) > 1) {
                if (intval($menu->order) !== 1) {
                    $menu->order = 1;
                    $menu->save();
                }
                continue;
            }
            \ExmentDB::transaction(function () use ($siblings, $menu) {
                foreach ($siblings->orderBy('order')->get() as $sibling) {
                    $sibling->order = intval($sibling->order) + 1;
                    $sibling->save();
                }
                $menu->order = 1;
                $menu->save();
            });
        }
    }

    /**
     * The parts of the views and the dashboards of the pack that the site has, when an install
     * stopped on the way left them out (the importer leaves a view or a dashboard that is there
     * as it is, its parts too):
     *
     * - a dashboard: the boxes of the pack at the places (row, column) where it has none
     * - a view: its columns (and groups), filters, sorts or totals when it has none of that kind.
     *   One kind at a time, so that a view the site changed (a column taken out) stays as it is.
     *
     * @param array<string, mixed> $json the pack
     */
    protected static function repairParts(array $json): void
    {
        \ExmentDB::transaction(function () use ($json) {
            foreach ((array) array_get($json, 'dashboards', []) as $item) {
                $dashboard = is_array($item) ? Dashboard::query()->where('dashboard_name', array_get($item, 'dashboard_name'))->first() : null;
                if (!isset($dashboard)) {
                    continue;
                }
                foreach ((array) array_get($item, 'dashboard_boxes', []) as $box) {
                    if (!is_array($box) || DashboardBox::query()->where('dashboard_id', $dashboard->id)
                        ->where('row_no', array_get($box, 'row_no'))->where('column_no', array_get($box, 'column_no'))->exists()) {
                        continue;
                    }
                    DashboardBox::importTemplate($box, true, ['parent' => $dashboard]);
                }
            }

            $kinds = (array) array_get(CustomView::$templateItems, 'children', []);
            foreach ((array) array_get($json, 'custom_views', []) as $item) {
                $suuid = is_array($item) ? array_get($item, 'suuid') : null;
                $view = is_nullorempty($suuid) ? null : CustomView::query()->withoutGlobalScopes()->where('suuid', $suuid)->first();
                if (!isset($view)) {
                    continue;
                }
                foreach ($kinds as $kind => $class) {
                    $parts = (array) array_get($item, $kind, []);
                    if (count($parts) === 0 || $view->{$kind}()->exists()) {
                        continue;
                    }
                    foreach ($parts as $part) {
                        if (is_array($part)) {
                            $class::importTemplate($part, true, ['parent' => $view]);
                        }
                    }
                }
            }
        });
    }

    /**
     * Whether the pack of a feature is not all there: an install of it has not finished (it
     * stopped on the way, as by a timeout: no mark of a finished install, FeaturePacks::markInstalled)
     * and a table or a dashboard of the pack is missing. Feature management installs it again
     * when the feature is on and its pack is not all there. Once an install has finished, what
     * the site removes later (a table, the dashboard) is the site's choice: it does not come back
     * by itself.
     *
     * A site that installed the pack before the mark existed has all its tables and dashboards:
     * the mark is written then, so they too do not come back once removed.
     */
    public static function incomplete(string $key): bool
    {
        if (static::installedAt($key) !== null) {
            return false;
        }
        $path = static::path($key);
        if (!isset($path) || !File::exists("$path/config.json")) {
            return false;
        }
        $json = json_decode_ex(File::get("$path/config.json"), true);
        if (!is_array($json)) {
            return false;
        }
        $names = function (string $list, string $name) use ($json): array {
            return array_values(array_unique(array_filter(array_map(function ($item) use ($name) {
                return is_array($item) ? array_get($item, $name) : null;
            }, (array) array_get($json, $list, [])))));
        };
        $tables = $names('custom_tables', 'table_name');
        $dashboards = $names('dashboards', 'dashboard_name');
        $missing = CustomTable::query()->whereIn('table_name', $tables)->count() < count($tables)
            || Dashboard::query()->whereIn('dashboard_name', $dashboards)->count() < count($dashboards);
        if (!$missing && Features::setting($key)['enabled']) {
            try {
                static::markInstalled($key);
            } catch (\Throwable $e) {
                \Log::warning('[Exment] the mark of the pack of ' . $key . ': ' . $e->getMessage());
            }
        }
        return $missing;
    }

    /**
     * The features chosen on /initialize: turned on and installed. A pack that fails does not
     * stop the setup (it is logged and told); it can be turned on later in Feature management.
     *
     * @param mixed $chosen the keys of the features
     * @return string[] the labels of the features that could not be installed
     */
    public static function installChosen($chosen): array
    {
        $failed = [];
        foreach (array_unique(array_map('strval', array_filter((array) $chosen, 'is_scalar'))) as $key) {
            if (!in_array($key, static::keys(), true)) {
                continue;
            }
            try {
                static::turnOn($key);
            } catch (\Throwable $e) {
                \Log::error($e);
                static::turnOff($key);
                $failed[] = Features::label($key);
            }
        }
        return $failed;
    }

    /**
     * Back to off (an install that failed): the next time it is turned on, it installs again.
     */
    public static function turnOff(string $key): void
    {
        try {
            $setting = Features::setting($key);
            Features::save([$key => ['enabled' => false, 'role_groups' => $setting['role_groups'], 'menu' => $setting['menu']]]);
            System::clearCache();
        } catch (\Throwable $e) {
            \Log::error($e);
        }
    }
}
