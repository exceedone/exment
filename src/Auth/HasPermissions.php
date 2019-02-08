<?php
namespace Exceedone\Exment\Auth;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Auth\Authenticatable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleValue;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\AuthUserOrgHelper;

trait HasPermissions
{
    use Authenticatable;
    use CanResetPassword;

    public function getNameAttribute()
    {
        return array_get($this->base_user->value, "user_name");
    }

    /**
     * Get avatar attribute.
     *
     * @param string $avatar
     *
     * @return string
     */
    public function getAvatarAttribute($avatar = null)
    {
        if ($avatar) {
            return Storage::disk(config('admin.upload.disk'))->url($avatar);
        }
        return asset('vendor/exment/images/user.png');
    }
    
    /**
     * whethere has permission, permission level
     * $role_key * if set array, check whether either items.
     * @param array|string $role_key
     */
    public function hasPermission($role_key)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        if (!is_array($role_key)) {
            $role_key = [$role_key];
        }

        $permissions = $this->allPermissions();
        foreach ($permissions as $permission) {
            // if role type is system, and has key
            if (RoleType::SYSTEM == $permission->getRoleType()
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
     * @return mixed
     */
    public function allPermissions() : Collection
    {
        // get session about role
        $roles = Session::get(Define::SYSTEM_KEY_SESSION_AUTHORITY);
        // if not exists, get permissons
        if (!isset($roles)) {
            $roles = $this->getPermissions();
        }

        $permissions = [];
        foreach ($roles as $key => $role) {
            if (RoleType::SYSTEM == $key) {
                array_push($permissions, new Permission([
                    'role_type' =>$key,
                    'table_name' => null,
                    'permission_details' =>$role,
                ]));
                continue;
            }
            foreach ($role as $k => $v) {
                array_push($permissions, new Permission([
                    'role_type' =>$key,
                    'table_name' =>$k,
                    'permission_details' =>$v,
                ]));
            }
        }

        return collect($permissions);
    }

    /**
     * Get all has permission tables of user.
     *
     * @return mixed
     */
    public function allHasPermissionTables($role_key) : Collection
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
        return collect($results);
    }

    /**
     * If visible for permission_details.
     * called form menu
     *
     * @param $item menu item
     * @param $target_tables output target tables. for template export. default nothing item
     *
     * @return bool
     */
    public function visible($item, $target_tables = []) : bool
    {
        if (empty($item)) {
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
            $first = collect($item['children'])->first(function ($child) use ($item, $target_tables) {
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
            return $permission->shouldPass(array_get($item, 'uri'));
        })) {
            return false;
        }
        return true;
    }

    /**
     * filter target model
     */
    public function filterModel($model, $table_name, $custom_view = null)
    {
        // view filter setting --------------------------------------------------
        // has $custom_view, filter
        if (isset($custom_view)) {
            $model = $custom_view->setValueFilters($model);
            $model = $custom_view->setValueSort($model);
        }

        ///// We don't need filter using role here because filter auto using global scope.

        return $model;
    }

    /**
     * get organizations that this_user joins.
     * @return mixed
     */
    public function getOrganizationIds()
    {
        // if system doesn't use organization, return empty array.
        if (!System::organization_available()) {
            return [];
        }
        if (Session::has(Define::SYSTEM_KEY_SESSION_ORGANIZATION_IDS)) {
            return Session::get(Define::SYSTEM_KEY_SESSION_ORGANIZATION_IDS);
        }
        $ids = AuthUserOrgHelper::getOrganizationIds();
        // set session.
        Session::put(Define::SYSTEM_KEY_SESSION_ORGANIZATION_IDS, $ids);
        return $ids;
    }

    /**
     * get permisson array.
     */
    protected function getPermissions()
    {
        $permission_system_auths = $this->getSystemPermissions();
        $permission_tables = $this->getCustomTablePermissions();

        Session::put(Define::SYSTEM_KEY_SESSION_AUTHORITY, [
            RoleType::SYSTEM => $permission_system_auths,
            RoleType::TABLE => $permission_tables]);
        Session::put(Define::SYSTEM_KEY_SESSION_INITIALIZE, true);

        return Session::get(Define::SYSTEM_KEY_SESSION_AUTHORITY);
    }

    /**
     * get all permissons for all custom tables.
     */
    protected function getCustomTablePermissions()
    {
        $organization_ids = $this->getOrganizationIds();

        // get permission_details for all tables. --------------------------------------------------
        $permission_details = [];
        for ($i = 0; $i < 2; $i++) {
            $query = DB::table('roles as a')
                ->join(SystemTableName::SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.role_id')
                ->join('custom_tables AS c', 'c.id', 'sa.morph_id')
                ->where('morph_type', RoleType::TABLE())
                ;
            // if $i == 0, then search as user
            if ($i == 0) {
                $query = $query->where('related_type', SystemTableName::USER)
                    ->where('related_id', $this->base_user_id);
            }
            // else then search as org.
            else {
                if (!System::organization_available()) {
                    continue;
                }
                $query = $query->where('related_type', 'organization')
                ->whereIn('related_id', $organization_ids);
            }

            $roles = array_merge(($roles ?? []), $query->orderBy('table_name')
                ->orderBy('id')
                ->get(['a.id', 'c.table_name', 'permissions'])->toArray());
        }
        // if (count($roles) == 0) {
        //     return [];
        // }

        $permissions = [];
        $before_table_name = null;
        foreach ($roles as $role) {
            $role = (array)$role;
            // if different table name, change target array.
            $table_name = array_get($role, 'table_name');
            if ($before_table_name != $table_name) {
                $permission_tables = [];
                $permissions[$table_name] = &$permission_tables;
                $before_table_name = $table_name;
            } else {
                $permission_tables = &$permissions[$table_name];
            }
            $permission_details = array_get($role, 'permissions');
            if (is_string($permission_details)) {
                $permission_details = json_decode($permission_details, true);
            }
            foreach ($permission_details as $key => $value) {
                // if permission value is 1, add permission.
                if (boolval($value) && !array_key_exists($key, $permission_tables)) {
                    $permission_tables[$key] = $value;
                }
            }
        }

        // check table all data
        $tables = CustomTable::all();
        foreach ($tables as $table) {
            $table_name = $table->table_name;
            if (boolval($table->getOption('all_user_editable_flg'))) {
                $permissions[$table_name][RoleValue::CUSTOM_VALUE_EDIT_ALL] = "1";
            }
            if (boolval($table->getOption('all_user_viewable_flg'))) {
                $permissions[$table_name][RoleValue::CUSTOM_VALUE_VIEW_ALL] = "1";
            }
            if (boolval($table->getOption('all_user_accessable_flg'))) {
                $permissions[$table_name][RoleValue::CUSTOM_VALUE_ACCESS_ALL] = "1";
            }
        }

        return $permissions;
    }

    /**
     * get all permissons for system.
     */
    protected function getSystemPermissions()
    {
        $organization_ids = $this->getOrganizationIds();

        // get all permissons for system. --------------------------------------------------
        $roles = DB::table('roles as a')
            ->join(SystemTableName::SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.role_id')
            ->where('morph_type', RoleType::SYSTEM())
            ->where(function ($query) use ($organization_ids) {
                $query->orWhere(function ($query) {
                    $query->where('related_type', SystemTableName::USER)
                    ->where('related_id', $this->base_user_id);
                });
                $query->orWhere(function ($query) use ($organization_ids) {
                    $query->where('related_type', SystemTableName::ORGANIZATION)
                    ->whereIn('related_id', $organization_ids);
                });
            })->get(['id', 'permissions'])->toArray();

        // if (count($roles) == 0) {
        //     return [];
        // }

        // get roles records
        $permissions = [];
        foreach ($roles as $role) {
            if (is_null($role->permissions)) {
                continue;
            }
            $role_details = json_decode($role->permissions, true);
            if (is_string($role_details)) {
                $role_details = json_decode($role_details, true);
            }
            foreach ($role_details as $key => $value) {
                // if permission value is 1, add permission.
                // $key = key($kv);
                // $value = $kv[$key];
                if (boolval($value) && !array_key_exists($key, $permissions)) {
                    $permissions[$key] = $value;
                }
            }
        }

        // if (count($permissions) == 0) {
        //     $permissions['dashboard'] = [];
        // }

        return $permissions;
    }
}
