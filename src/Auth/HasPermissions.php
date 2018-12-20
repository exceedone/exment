<?php
namespace Exceedone\Exment\Auth;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Auth\Authenticatable;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Enums\AuthorityType;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Carbon\Carbon;

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
     * $authority_key * if set array, check whether either items.
     * @param array|string $authority_key
     */
    public function hasPermission($authority_key)
    {
        // if system doesn't use authority, return true
        if (!System::authority_available()) {
            return true;
        }

        if (!is_array($authority_key)) {
            $authority_key = [$authority_key];
        }

        $permissions = $this->allPermissions();
        foreach ($permissions as $permission) {
            // if authority type is system, and has key
            if (AuthorityType::SYSTEM()->match($permission->getAuthorityType())
                && array_keys_exists($authority_key, $permission->getAuthorities())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all permissions of user.
     *
     * @return mixed
     */
    public function allPermissions() : Collection
    {
        // get session about authority
        $authorities = Session::get(Define::SYSTEM_KEY_SESSION_AUTHORITY);
        // if not exists, get permissons
        if (!isset($authorities)) {
            $authorities = $this->getPermissions();
        }

        $permissions = [];
        foreach ($authorities as $key => $authority) {
            if (AuthorityType::SYSTEM()->match($key)) {
                array_push($permissions, new Permission([
                    'authority_type' =>$key,
                    'table_name' => null,
                    'authorities' =>$authority,
                ]));
                continue;
            }
            foreach ($authority as $k => $v) {
                array_push($permissions, new Permission([
                    'authority_type' =>$key,
                    'table_name' =>$k,
                    'authorities' =>$v,
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
    public function allHasPermissionTables($authority_key) : Collection
    {
        $results = [];
        // get tables
        $custom_tables = CustomTable::all();
        // loop for table
        foreach ($custom_tables as $custom_table) {
            if ($custom_table->hasPermission($authority_key)) {
                $results[] = $custom_table;
            }
        }
        return collect($results);
    }

    /**
     * If visible for roles.
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
            $model = $custom_view->setValueFilter($model);
            $model = $custom_view->setValueSort($model);
        }

        ///// We don't need filter using authority here because filter auto using global scope.

        return $model;
    }

    /**
     * get organizations that user joins.
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

        // get organization ids.
        $db_table_name_organization = getDBTableName(SystemTableName::ORGANIZATION);
        $db_table_name_pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        $ids = DB::table($db_table_name_organization.' AS o1')
           ->leftJoin($db_table_name_organization.' AS o2', 'o2.parent_id', '=', 'o1.id')
           ->leftJoin($db_table_name_organization.' AS o3', 'o3.parent_id', '=', 'o3.id')
           ->leftJoin($db_table_name_pivot.' AS ou1', 'ou1.parent_id', '=', 'o1.id')
           ->leftJoin($db_table_name_pivot.' AS ou2', 'ou2.parent_id', '=', 'o2.id')
           ->leftJoin($db_table_name_pivot.' AS ou3', 'ou3.parent_id', '=', 'o3.id')
           ->orWhere('ou1.child_id', $this->base_user_id)
           ->orWhere('ou2.child_id', $this->base_user_id)
           ->orWhere('ou3.child_id', $this->base_user_id)
           ->get(['o1.id'])->pluck('id')->toArray();
        // set session.
        Session::put(Define::SYSTEM_KEY_SESSION_ORGANIZATION_IDS);
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
            AuthorityType::SYSTEM => $permission_system_auths,
            AuthorityType::TABLE => $permission_tables]);
        Session::put(Define::SYSTEM_KEY_SESSION_INITIALIZE, true);

        return Session::get(Define::SYSTEM_KEY_SESSION_AUTHORITY);
    }

    /**
     * get all permissons for all custom tables.
     */
    protected function getCustomTablePermissions()
    {
        $organization_ids = $this->getOrganizationIds();

        // get authorities for all tables. --------------------------------------------------
        $authorities = [];
        for ($i = 0; $i < 2; $i++) {
            $query = DB::table('authorities as a')
                ->join(SystemTableName::SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.authority_id')
                ->join('custom_tables AS c', 'c.id', 'sa.morph_id')
                ->where('morph_type', AuthorityType::TABLE())
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

            $authorities = array_merge($authorities, $query->orderBy('table_name')
                ->orderBy('id')
                ->get(['a.id', 'c.table_name', 'permissions'])->toArray());
        }
        if (count($authorities) == 0) {
            return [];
        }

        $permissions = [];
        $before_table_name = null;
        foreach ($authorities as $authority) {
            $authority = (array)$authority;
            // if different table name, change target array.
            $table_name = array_get($authority, 'table_name');
            if ($before_table_name != $table_name) {
                $permission_tables = [];
                $permissions[$table_name] = &$permission_tables;
                $before_table_name = $table_name;
            } else {
                $permission_tables = &$permissions[$table_name];
            }
            $authority_details = array_get($authority, 'permissions');
            if (is_string($authority_details)) {
                $authority_details = json_decode($authority_details, true);
            }
            foreach ($authority_details as $key => $value) {
                // if permission value is 1, add permission.
                if (boolval($value) && !array_key_exists($key, $permission_tables)) {
                    $permission_tables[$key] = $value;
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
        $organization_ids = $this->getOrganizationIds();

        // get all permissons for system. --------------------------------------------------
        $authorities = DB::table('authorities as a')
            ->join(SystemTableName::SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.authority_id')
            ->where('morph_type', AuthorityType::SYSTEM())
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

        if (count($authorities) == 0) {
            return [];
        }

        // get authorities records
        $permissions = [];
        foreach ($authorities as $authority) {
            if (is_null($authority->permissions)) {
                continue;
            }
            $authority_details = json_decode($authority->permissions, true);
            if (is_string($authority_details)) {
                $authority_details = json_decode($authority_details, true);
            }
            foreach ($authority_details as $key => $value) {
                // if permission value is 1, add permission.
                // $key = key($kv);
                // $value = $kv[$key];
                if (boolval($value) && !array_key_exists($key, $permissions)) {
                    $permissions[$key] = $value;
                }
            }
        }

        if (count($permissions) == 0) {
            $permissions['dashboard'] = [];
        }

        return $permissions;
    }

    /**
     * get value from user setting table
     */
    public function getSettingValue($key)
    {
        if (is_null($this->base_user)) {
            return null;
        }
        // get settings from settion
        $settings = Session::get("user_setting.$key");
        // if empty, get User Setting table
        if (!isset($settings)) {
            $usersetting = UserSetting::firstOrCreate(['base_user_id' => $this->base_user->id]);
            $settings = $usersetting->settings ?? [];
        }
        return array_get($settings, $key) ?? null;
    }
    public function setSettingValue($key, $value)
    {
        if (is_null($this->base_user)) {
            return null;
        }
        // set User Setting table
        $usersetting = UserSetting::firstOrCreate(['base_user_id' => $this->base_user->id]);
        $settings = $usersetting->settings;
        if (!isset($settings)) {
            $settings = [];
        }
        // set value
        array_set($settings, $key, $value);
        $usersetting->settings = $settings;
        $usersetting->saveOrFail();

        // put session
        Session::put("user_setting.$key", $settings);
    }
}
