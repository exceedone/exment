<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Services\Features;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin setting → Feature management: the features of Exment and of the
 * plugins on one page. For each: whether it is used, who may use it, and
 * whether its menu is shown. The system administrators only (Auth\Permission,
 * "feature"). See Services\Features for the rules.
 *
 * The plugins keep what they had: whether one is active is shown here and
 * changed on the page of the plugin (it checks what a plugin needs to be
 * active); who may use it is the plugin_access permission of the role groups
 * (the same rows as the page of the role groups); only its menu flag is kept
 * in the setting.
 */
class FeatureController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('feature.header'), exmtrans('feature.header'), exmtrans('feature.description'), 'fa-toggle-on');
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $roleGroups = RoleGroup::query()->orderBy('role_group_order')->orderBy('id')->get(['id', 'role_group_view_name'])
            ->map(function ($group) {
                return ['id' => intval($group->id), 'name' => strval($group->role_group_view_name)];
            })->all();

        $core = [];
        $byPlugin = [];
        foreach (Features::definitions() as $key => $definition) {
            $row = $this->featureRow(strval($key), $definition);
            if (isset($definition['plugin'])) {
                $plugin = Features::pluginOf(strval($key));
                $byPlugin[isset($plugin) ? $plugin->plugin_name : ''][] = $row;
                continue;
            }
            $core[] = $row;
        }

        $plugins = [];
        foreach (Plugin::query()->orderBy('plugin_view_name')->get() as $plugin) {
            $plugins[] = $this->pluginRow($plugin) + ['features' => $byPlugin[$plugin->plugin_name] ?? []];
        }

        $this->AdminContent($content);
        $content->row(view('exment::feature.index', [
            'features' => $core,
            'plugins' => $plugins,
            'role_groups' => $roleGroups,
            'action' => admin_url('feature'),
            'role_group_url' => admin_url('role_group'),
            'system_url' => admin_url('system'),
            'menu_url' => admin_url('auth/menu'),
            'read_only' => DemoMode::enabled(),
        ]));
        return $content;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request)
    {
        if (DemoMode::enabled()) {
            admin_toastr(DemoMode::message(), 'error');
            return redirect(admin_url('feature'));
        }

        // the last field of the form: without it, the form was cut (max_input_vars) and is not saved
        if (strval($request->input('complete')) !== '1') {
            admin_toastr(exmtrans('feature.message_incomplete'), 'error');
            return redirect(admin_url('feature'));
        }
        $input = (array)$request->input('features', []);
        $values = [];
        $definitions = Features::definitions();
        foreach ($definitions as $key => $definition) {
            if (Features::locked(strval($key)) || !array_key_exists($key, $input)) {
                continue;
            }
            $row = (array)$input[$key];
            $values[$key] = [
                'enabled' => boolval(array_get($row, 'enabled', false)),
                // "all": null; chosen groups: the list (none: the system administrators only)
                'role_groups' => strval(array_get($row, 'all', '1')) === '1' || !$definition['who'] ? null : (array)array_get($row, 'role_groups', []),
                'menu' => boolval(array_get($row, 'menu', false)),
            ];
        }

        $pluginInput = (array)$request->input('plugins', []);
        DB::transaction(function () use (&$values, $pluginInput, $definitions) {
            foreach (Plugin::query()->get() as $plugin) {
                if (!array_key_exists($plugin->plugin_name, $pluginInput)) {
                    continue;
                }
                $row = (array)$pluginInput[$plugin->plugin_name];
                $values[Features::PLUGIN_PREFIX . $plugin->plugin_name] = ['menu' => boolval(array_get($row, 'menu', false))];
                if ($this->pluginAccessFiltered($plugin) && !boolval($plugin->getOption('all_user_enabled'))) {
                    // only what was changed on this page (what was changed meanwhile on the page of the role groups stays)
                    $shown = array_map('intval', (array)json_decode(strval(array_get($row, 'shown', '[]')), true));
                    $this->savePluginAccess($plugin, $shown, array_map('intval', (array)array_get($row, 'role_groups', [])));
                }
            }
            Features::save($values);
            // the cache of the settings, once they are in the database
            \Exceedone\Exment\Model\System::clearCacheAfterCommit();

            // a feature turned on with its menu: its menu again when the row was removed
            foreach ($values as $key => $value) {
                if (array_key_exists($key, $definitions) && boolval($value['enabled'] ?? false) && $value['menu'] && isset($definitions[$key]['menu'])) {
                    $this->ensureSystemMenu(strval($definitions[$key]['menu']));
                }
            }
        });

        admin_toastr(exmtrans('common.message.success_execute'));
        return redirect(admin_url('feature'));
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function featureRow(string $key, array $definition): array
    {
        $requires = $definition['requires'];
        return [
            'key' => $key,
            'label' => Features::label($key),
            'description' => Features::description($key),
            'setting' => Features::setting($key),
            'new' => Features::isNew($key),
            'locked' => Features::locked($key),
            'reason' => Features::reason($key),
            'who' => $definition['who'],
            'requires' => isset($requires) && Features::exists($requires) ? Features::label($requires) : null,
            'has_menu' => isset($definition['menu']),
            'menu_exists' => isset($definition['menu']) && $this->systemMenuExists(strval($definition['menu'])),
            'note' => $key === Features::STUDIO ? exmtrans('feature.studio_note') : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pluginRow(Plugin $plugin): array
    {
        $filtered = $this->pluginAccessFiltered($plugin);
        $allUsers = boolval($plugin->getOption('all_user_enabled'));
        return [
            'name' => $plugin->plugin_name,
            'label' => $plugin->plugin_view_name,
            'version' => $plugin->version,
            'enabled' => boolval($plugin->active_flg),
            'edit_url' => admin_urls('plugin', $plugin->id, 'edit'),
            // who may use it: "all" when it does not ask (its type, or its option)
            'everyone' => !$filtered || $allUsers,
            'everyone_reason' => !$filtered ? exmtrans('feature.plugin_everyone_type') : ($allUsers ? exmtrans('feature.plugin_everyone_option') : null),
            'role_groups' => $filtered ? $this->pluginAccessGroups($plugin) : [],
            'menu' => !Features::hidesPluginMenu($plugin->plugin_name),
        ];
    }

    protected function pluginAccessFiltered(Plugin $plugin): bool
    {
        foreach ((array)$plugin->plugin_types as $type) {
            if (in_array($type, PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE())) {
                return true;
            }
        }
        return false;
    }

    /**
     * The role groups given "plugin_access" for the plugin.
     *
     * @return int[]
     */
    protected function pluginAccessGroups(Plugin $plugin): array
    {
        return RoleGroupPermission::query()->where('role_group_permission_type', RoleType::PLUGIN)
            ->where('role_group_target_id', $plugin->id)->get()
            ->filter(function (RoleGroupPermission $permission) {
                return in_array(Permission::PLUGIN_ACCESS, (array)$permission->permissions, true);
            })->pluck('role_group_id')->map(function ($id) {
                return intval($id);
            })->values()->all();
    }

    /**
     * Give "plugin_access" to the role groups checked on the page that were
     * not checked when it was shown, and take it from those unchecked. The
     * other groups, and the other permissions of the plugin (as
     * plugin_setting), stay.
     *
     * @param int[] $shown the groups checked when the page was shown
     * @param int[] $checked the groups checked now
     */
    protected function savePluginAccess(Plugin $plugin, array $shown, array $checked): void
    {
        $add = array_diff($checked, $shown);
        $remove = array_diff($shown, $checked);
        foreach (array_merge($add, $remove) as $groupId) {
            if (!RoleGroup::query()->whereKey($groupId)->exists()) {
                continue;
            }
            $permission = RoleGroupPermission::firstOrNew([
                'role_group_id' => $groupId,
                'role_group_permission_type' => RoleType::PLUGIN,
                'role_group_target_id' => $plugin->id,
            ]);
            $list = array_values(array_filter((array)($permission->permissions ?? [])));
            $want = in_array($groupId, $add, true);
            if (in_array(Permission::PLUGIN_ACCESS, $list, true) === $want) {
                continue;
            }
            $permission->permissions = $want ? array_merge($list, [Permission::PLUGIN_ACCESS]) : array_values(array_diff($list, [Permission::PLUGIN_ACCESS]));
            $permission->save();
        }
    }

    protected function systemMenuExists(string $menuName): bool
    {
        return DB::table(config('admin.database.menu_table', 'admin_menu'))->where('menu_type', 'system')->where('menu_name', $menuName)->exists();
    }

    /**
     * Make the system menu of a feature when the site has none (it was
     * removed to hide the feature): after the home for the file server, at
     * the end of "Admin Setting" for the others.
     */
    protected function ensureSystemMenu(string $menuName): void
    {
        $table = config('admin.database.menu_table', 'admin_menu');
        if ($this->systemMenuExists($menuName)) {
            return;
        }
        $define = array_get(Define::MENU_SYSTEM_DEFINITION, $menuName);
        if (!is_array($define)) {
            return;
        }
        $parentId = 0;
        $order = 1;
        $home = DB::table($table)->where('menu_type', 'system')->where('menu_name', 'home')->first();
        $admin = DB::table($table)->where('menu_name', 'admin')->where('menu_type', 'parent_node')->first();
        if ($menuName !== 'file_server' && isset($admin)) {
            $parentId = intval($admin->id);
            $order = intval(DB::table($table)->where('parent_id', $parentId)->max('order')) + 1;
        } elseif (isset($home)) {
            $parentId = intval($home->parent_id);
            $order = intval($home->order) + 1;
        }
        DB::table($table)->insert([
            'parent_id' => $parentId,
            'order' => $order,
            'title' => exmtrans('menu.system_definitions.' . $menuName),
            'icon' => array_get($define, 'icon'),
            'uri' => array_get($define, 'uri'),
            'menu_type' => 'system',
            'menu_name' => $menuName,
            'menu_target' => $menuName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
