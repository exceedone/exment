<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
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

        // true: what is there is left as it is (only what is missing is made)
        (new TemplateImporter())->import($json, false, true);

        $newTables = array_values(array_diff($tableNames, $existingTables));
        if (count($newTables) === 0 || $existingGroups->isEmpty()) {
            return;
        }
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
        System::clearCache();
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
