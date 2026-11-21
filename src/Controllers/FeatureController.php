<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Services\Features;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin setting → Feature management: the features of Exment and the plugins
 * on one page. For each: whether it is used, who may use it, and whether its
 * menu is shown. The system administrators only (Auth\Permission, "feature").
 *
 * The plugins keep what they had: "used" is their active flag and "who" is
 * the plugin_access permission of the role groups (the same rows as the page
 * of the role groups); only their menu flag is kept in the setting.
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

        $features = [];
        foreach (Features::definitions() as $key => $definition) {
            $features[] = ['key' => $key, 'definition' => $definition, 'setting' => Features::setting($key)];
        }

        $plugins = [];
        foreach (Plugin::query()->orderBy('plugin_view_name')->get() as $plugin) {
            $plugins[] = $this->pluginRow($plugin);
        }

        $this->AdminContent($content);
        $content->row(view('exment::feature.index', [
            'features' => $features,
            'plugins' => $plugins,
            'role_groups' => $roleGroups,
            'action' => admin_url('feature'),
            'role_group_url' => admin_url('role_group'),
        ]));
        return $content;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request)
    {
        $input = (array)$request->input('features', []);
        $values = [];
        foreach (array_keys(Features::definitions()) as $key) {
            $row = (array)array_get($input, $key, []);
            $values[$key] = [
                'enabled' => boolval(array_get($row, 'enabled', false)),
                'role_groups' => array_get($row, 'all', '1') === '1' ? [] : (array)array_get($row, 'role_groups', []),
                'menu' => boolval(array_get($row, 'menu', false)),
            ];
        }

        $pluginInput = (array)$request->input('plugins', []);
        DB::transaction(function () use ($values, $pluginInput) {
            foreach (Plugin::query()->get() as $plugin) {
                if (!array_key_exists($plugin->plugin_name, $pluginInput)) {
                    continue;
                }
                $row = (array)$pluginInput[$plugin->plugin_name];
                $values[Features::PLUGIN_PREFIX . $plugin->plugin_name] = ['menu' => boolval(array_get($row, 'menu', false))];

                $active = boolval(array_get($row, 'enabled', false));
                if (boolval($plugin->active_flg) !== $active) {
                    $plugin->active_flg = $active;
                    $plugin->save();
                }
                if ($this->pluginAccessFiltered($plugin) && !boolval($plugin->getOption('all_user_enabled'))) {
                    $this->savePluginAccess($plugin, array_map('intval', (array)array_get($row, 'role_groups', [])));
                }
            }
            Features::save($values);
        });

        admin_toastr(exmtrans('common.message.success_execute'));
        return redirect(admin_url('feature'));
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
            // who may use it: "all" when it does not ask (its type, or its option)
            'everyone' => !$filtered || $allUsers,
            'everyone_reason' => !$filtered ? exmtrans('feature.plugin_everyone_type') : ($allUsers ? exmtrans('feature.plugin_everyone_option') : null),
            'role_groups' => $filtered ? $this->pluginAccessGroups($plugin) : [],
            'menu' => !Features::hidesPluginMenu($plugin->plugin_name),
        ];
    }

    protected function pluginAccessFiltered(Plugin $plugin): bool
    {
        return collect($plugin->plugin_types)->contains(function ($type) {
            return in_array($type, PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE());
        });
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
            })->pluck('role_group_id')->map('intval')->values()->all();
    }

    /**
     * Give "plugin_access" to the role groups chosen, and take it from the
     * others (their other permissions of the plugin, as plugin_setting, stay).
     *
     * @param int[] $groupIds
     */
    protected function savePluginAccess(Plugin $plugin, array $groupIds): void
    {
        foreach (RoleGroup::query()->get(['id']) as $group) {
            $permission = RoleGroupPermission::firstOrNew([
                'role_group_id' => $group->id,
                'role_group_permission_type' => RoleType::PLUGIN,
                'role_group_target_id' => $plugin->id,
            ]);
            $list = array_values(array_filter((array)($permission->permissions ?? [])));
            $has = in_array(Permission::PLUGIN_ACCESS, $list, true);
            $want = in_array(intval($group->id), $groupIds, true);
            if ($has === $want) {
                continue;
            }
            $list = $want ? array_merge($list, [Permission::PLUGIN_ACCESS]) : array_values(array_diff($list, [Permission::PLUGIN_ACCESS]));
            $permission->permissions = $list;
            $permission->save();
        }
    }
}
