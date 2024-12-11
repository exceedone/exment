<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Model\RoleGroupPermission;
use Illuminate\Support\Collection;
use Illuminate\Auth\Authenticatable;
use Exceedone\Exment\Auth\Permission as AuthPermission;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Services\AuthUserOrgHelper;

trait HasPermissions
{
    use Authenticatable;
    use CanResetPassword;

    public function isAdministrator()
    {
        return collect(System::system_admin_users())->contains($this->getUserId());
    }

    /**
     * Whether has permission, permission level
     * $role_key * if set array, check whether either items.
     * @param array|string $role_key
     */
    public function hasPermission($role_key)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        if ($role_key == Permission::SYSTEM) {
            return $this->isAdministrator();
        }

        if (!is_array($role_key)) {
            $role_key = [$role_key];
        }

        $permissions = $this->allPermissions();
        foreach ($permissions as $permission) {
            // check system permission
            if (RoleType::SYSTEM == $permission->getRoleType()
                && array_key_exists('system', $permission->getPermissionDetails())) {
                return true;
            }

            // if role type is system, and has key
            if (RoleType::SYSTEM == $permission->getRoleType()
                && array_keys_exists($role_key, $permission->getPermissionDetails())) {
                return true;
            }
        }
        return false;
    }

    /**
     * whethere has permission, permission level
     * $role_key * if set array, check whether either items.
     * Checking also each table. If there is even one, return true.
     * @param array|string $role_key
     */
    public function hasPermissionContainsTable($role_key)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        if ($role_key == Permission::SYSTEM) {
            return $this->isAdministrator();
        }

        if (!is_array($role_key)) {
            $role_key = [$role_key];
        }

        $permissions = $this->allPermissions();
        foreach ($permissions as $permission) {
            // if role type is system, and has key
            if (array_keys_exists($role_key, $permission->getPermissionDetails())) {
                return true;
            }
        }
        return false;
    }

    /**
     * whethere has permission, permission level
     * $role_key * if set array, check whether either items.
     * Checking target plugin.
     * @param Plugin $plugin
     * @param array|string $role_key
     */
    public function hasPermissionPlugin($plugin, $role_key)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        $plugin = Plugin::getEloquent($plugin);
        if (!isset($plugin)) {
            return false;
        }

        $role_key = stringToArray($role_key);

        // check all access
        if (in_array(Permission::PLUGIN_ACCESS, $role_key)) {
            if (boolval($plugin->getOption('all_user_enabled'))) {
                return true;
            }

            // if not check permission for access, return true
            $plugin_types = array_get($plugin, 'plugin_types');
            if (!array_intersect($plugin_types, PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE())) {
                return true;
            }
        }

        $permissions = $this->allPermissions();
        foreach ($permissions as $permission) {
            // check system permission
            if (RoleType::SYSTEM == $permission->getRoleType()
                && array_key_exists('system', $permission->getPermissionDetails())) {
                return true;
            }

            // if role type is system, and has plugin all
            if (RoleType::SYSTEM == $permission->getRoleType()
                && array_keys_exists(Permission::PLUGIN_ALL, $permission->getPermissionDetails())) {
                return true;
            }

            // if target plugin, and has key
            if ($permission->getPluginId() == $plugin->id
                && array_keys_exists($role_key, $permission->getPermissionDetails())) {
                return true;
            }
        }
        return false;
    }

    /**
     * whether user has no permission
     * if no permission, show message on dashboard
     */
    public function noPermission()
    {
        // if system doesn't use role, return false
        if (!System::permission_available()) {
            return false;
        }
        $permissions = $this->allPermissions();
        foreach ($permissions as $permission) {
            // roles, this user has permission
            if (count($permission->getPermissionDetails()) > 0) {
                return false;
            }
        }
        return true;
    }
    /**
     * Get all permissions of user.
     *
     * @return Collection
     */
    public function allPermissions(): Collection
    {
        // get request session about role
        $roles = System::requestSession(Define::SYSTEM_KEY_SESSION_AUTHORITY, function () {
            return $this->getPermissions();
        });

        $permissions = [];
        foreach ($roles as $key => $role) {
            if (RoleType::SYSTEM == $key) {
                $permissions[] = new AuthPermission([
                    'role_type' =>$key,
                    'table_name' => null,
                    'permission_details' =>$role,
                ]);
                continue;
            } elseif (RoleType::TABLE == $key) {
                foreach ($role as $k => $v) {
                    $permissions[] =  new AuthPermission([
                        'role_type' =>$key,
                        'table_name' =>$k,
                        'permission_details' =>$v,
                    ]);
                }
            } elseif (RoleType::PLUGIN == $key) {
                foreach ($role as $k => $v) {
                    $permissions[] =  new AuthPermission([
                        'role_type' => $key,
                        'table_name' => null,
                        'plugin_id' => $k,
                        'permission_details' =>$v,
                    ]);
                }
            }
        }

        /** @var Collection $collection */
        $collection = collect($permissions);
        return $collection;
    }

    /**
     * Get all has permission tables of user.
     *
     * @param $role_key
     * @return Collection
     */
    public function allHasPermissionTables($role_key): Collection
    {
        $results = [];
        // get tables
        $custom_tables = CustomTable::all();
        // loop for table
        foreach ($custom_tables as $custom_table) {
            if ($custom_table->hasPermission($role_key)) {
                $results[] = $custom_table;
            }
        }
        /** @var Collection $collection */
        $collection = collect($results);
        return $collection;
    }

    /**
     * If visible for permission_details.
     * called form menu
     *
     * @param array|string $item
     * @param array $target_tables output target tables. for template export. default nothing item
     *
     * @return bool
     */
    public function visible($item, $target_tables = []): bool
    {
        if (is_string($item)) {
            $item = [
                'uri' => $item
            ];
        } elseif (empty($item)) {
            return false;
        }

        // if organization and not use org setting, return false
        if (array_get($item, 'menu_type') == MenuType::TABLE
            && array_get($item, 'table_name') == SystemTableName::ORGANIZATION
            && !System::organization_available()) {
            return false;
        }

        // if $item has children, get children's visible result.
        // if children have true result, return true;
        if (array_key_exists('children', $item)) {
            $first = collect($item['children'])->first(function ($child) use ($target_tables) {
                return $this->visible($child, $target_tables);
            });
            return !is_null($first);
        }

        // if has target tables.
        if (count($target_tables) > 0) {
            // if $item->menu_name is not contains $target_tables, return false
            if (!collect($target_tables)->first(function ($target_table) use ($item) {
                return array_get($item, 'table_name') == $target_table;
            })) {
                return false;
            }
        }

        // get permission for target endpoint
        $permissons = $this->allPermissions();

        if (!$permissons->first(function ($permission) use ($item) {
            return $permission->shouldPassEndpoint(array_get($item, 'uri'), $item);
        })) {
            return false;
        }
        return true;
    }

    /**
     * get organizations that this_user joins.
     *
     * IMPORTANT: Please look this topic.
     * https://exment.net/docs/#/ja/developing_memo
     * @return array
     */
    public function getOrganizationIdsForQuery($filterType = JoinedOrgFilterType::ALL)
    {
        return System::requestSession(Define::SYSTEM_KEY_SESSION_ORGANIZATION_IDS . '_' . $filterType, function () use ($filterType) {
            //return $this->base_user->getOrganizationIdsForQuery($filterType);
            // if system doesn't use organization, return empty array.
            if (!System::organization_available()) {
                return [];
            }
            return AuthUserOrgHelper::getOrganizationIdsForQuery($filterType, $this->base_user_id);
        });
    }


    /**
     * Get user and organization ids for query whereInMultiple.
     *
     * @param string $filterType
     * @return array offset 0 : type, 1 : user or organization id.
     */
    public function getUserAndOrganizationIds($filterType = JoinedOrgFilterType::ALL)
    {
        $results = [[SystemTableName::USER, $this->getUserId()]];

        if (System::organization_available()) {
            collect($this->getOrganizationIdsForQuery($filterType))->each(function ($id) use (&$results) {
                $results[] = [SystemTableName::ORGANIZATION, $id];
            });
        }

        return $results;
    }

    /**
     * get permisson array.
     */
    protected function getPermissions()
    {
        $authority = System::requestSession(Define::SYSTEM_KEY_SESSION_AUTHORITY, function () {
            return [
                RoleType::SYSTEM => $this->getSystemPermissions(),
                RoleType::TABLE => $this->getCustomTablePermissions(),
                RoleType::PLUGIN => $this->getPluginPermissions(),
            ];
        });
        return $authority;
    }

    /**
     * get all permissons for all custom tables.
     */
    protected function getCustomTablePermissions()
    {
        // get all permissons for system. --------------------------------------------------
        $roles = $this->getPermissionItems();

        // get permission_details for all tables. --------------------------------------------------
        $permission_details = [];
        $permissions = [];

        $tables = CustomTable::allRecords();
        foreach ($roles as $role) {
            /** @var RoleGroupPermission $role_group_permission */
            foreach ($role->role_group_permissions as $role_group_permission) {
                if (!isset($role_group_permission->permissions)) {
                    continue;
                }
                if ($role_group_permission->role_group_permission_type != RoleType::TABLE) {
                    continue;
                }

                $custom_table = $tables->first(function($item) use ($role_group_permission) {
                    return $item->id == $role_group_permission->role_group_target_id;
                });
                if (!isset($custom_table)) {
                    continue;
                }

                $role_details = $role_group_permission->permissions;
                foreach ($role_details as $value) {
                    if (!isset($permissions[$custom_table->table_name])) {
                        $permissions[$custom_table->table_name] = [];
                    }
                    if (!array_key_exists($value, $permissions[$custom_table->table_name])) {
                        $permissions[$custom_table->table_name][$value] = 1;
                    }
                }
            }
        }

        foreach ($tables as $table) {
            $table_name = $table->table_name;
            if (boolval($table->getOption('all_user_editable_flg'))) {
                $permissions[$table_name][Permission::CUSTOM_VALUE_EDIT_ALL] = "1";
            }
            if (boolval($table->getOption('all_user_viewable_flg'))) {
                $permissions[$table_name][Permission::CUSTOM_VALUE_VIEW_ALL] = "1";
            }
            if (boolval($table->getOption('all_user_accessable_flg'))) {
                $permissions[$table_name][Permission::CUSTOM_VALUE_ACCESS_ALL] = "1";
            }
        }

        return $permissions;
    }

    /**
     * get Plugin permissons.
     */
    protected function getPluginPermissions()
    {
        // get all permissons for system. --------------------------------------------------
        $roles = $this->getPermissionItems();

        // get permission_details for all tables. --------------------------------------------------
        $permission_details = [];
        $permissions = [];
        $plugins = Plugin::allRecords();

        foreach ($roles as $role) {
            /** @var RoleGroupPermission $role_group_permission */
            foreach ($role->role_group_permissions as $role_group_permission) {
                if (!isset($role_group_permission->permissions)) {
                    continue;
                }
                if ($role_group_permission->role_group_permission_type != RoleType::PLUGIN) {
                    continue;
                }

                $plugin = $plugins->first(function($item) use ($role_group_permission) {
                    return $item->id == $role_group_permission->role_group_target_id;
                });
                if (!isset($plugin)) {
                    continue;
                }

                $role_details = $role_group_permission->permissions;
                foreach ($role_details as $value) {
                    if (!isset($permissions[$plugin->id])) {
                        $permissions[$plugin->id] = [];
                    }
                    if (!array_key_exists($value, $permissions[$plugin->id])) {
                        $permissions[$plugin->id][$value] = 1;
                    }
                }
            }
        }

        return $permissions;
    }

    /**
     * get all permissons for system.
     */
    protected function getSystemPermissions()
    {
        // get all permissons for system. --------------------------------------------------
        $roles = $this->getPermissionItems();

        // get roles records
        $permissions = [];
        foreach ($roles as $role) {
            foreach ($role->role_group_permissions as $role_group_permission) {
                if (is_null($role_group_permission->permissions)) {
                    continue;
                }
                if ($role_group_permission->role_group_permission_type != RoleType::SYSTEM) {
                    continue;
                }

                $role_details = $role_group_permission->permissions;
                foreach ($role_details as $value) {
                    if (!array_key_exists($value, $permissions)) {
                        $permissions[$value] = "1";
                    }
                }
            }
        }

        // set system setting
        if (!array_has($permissions, 'system') && collect(System::system_admin_users())->first(function ($system_admin_user) {
            return $system_admin_user == $this->getUserId();
        })) {
            $permissions['system'] = "1";
        }

        return $permissions;
    }

    protected function getPermissionItems()
    {
        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ALL);
        $organization_ids = $this->getOrganizationIdsForQuery($enum);

        // get all permissons for system. --------------------------------------------------
        return RoleGroup::getHasPermissionRoleGroup($this->getUserId(), $organization_ids);
    }
}
