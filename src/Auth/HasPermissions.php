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
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Enums\AuthorityType;
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
        return $this->get_avatar($avatar);
    }
    
    protected function get_avatar($avatar = null)
    {
        if ($avatar) {
            return Storage::disk(config('admin.upload.disk'))->url($avatar);
        } elseif ($this->login_user && !is_nullorempty($this->avatar)) {
            return Storage::disk(config('admin.upload.disk'))->url($this->avatar);
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
     * whethere has permission selecting table, permission level
     * @param array|string $authority_key * if set array, check whether either items.
     */
    public function hasPermissionTable($table, $authority_key)
    {
        // if system doesn't use authority, return true
        if (!System::authority_available()) {
            return true;
        }
        $table_name = CustomTable::getEloquent($table)->table_name;
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

            // if authority type is table, and match table name
            elseif (AuthorityType::TABLE()->match($permission->getAuthorityType()) && $permission->getTableName() == $table_name) {
                // if user has authority
                if (array_keys_exists($authority_key, $permission->getAuthorities())) {
                    return true;
                }
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
            if ($this->hasPermissionTable($custom_table->table_name, $authority_key)) {
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
     * Whether user has permission about target id data.
     */
    public function hasPermissionData($id, $table_name)
    {
        // if system doesn't use authority, return true
        if (!System::authority_available()) {
            return true;
        }

        // if user doesn't have all permissons about target table, return false.
        if (!$this->hasPermissionTable($table_name, Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return false;
        }

        // if user has all edit table, return true.
        if ($this->hasPermissionTable($table_name, Define::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL)) {
            return true;
        }

        $model = getModelName($table_name)::find($id);
        // else, get model using value_authoritable.
        // if count > 0, return true.
        $rows = $model->getAuthoritable(Define::SYSTEM_TABLE_NAME_USER);
        if (isset($rows) && count($rows) > 0) {
            return true;
        }

        // else, get model using value_authoritable. (only that system uses organization.)
        // if count > 0, return true.
        if (System::organization_available()) {
            $rows = $model->getAuthoritable(Define::SYSTEM_TABLE_NAME_ORGANIZATION);
            if (isset($rows) && count($rows) > 0) {
                return true;
            }
        }

        // else, return false.
        return false;
    }

    /**
     * Whether user has edit permission about target id data.
     */
    public function hasPermissionEditData($id, $table_name)
    {
        // if system doesn't use authority, return true
        if (!System::authority_available()) {
            return true;
        }

        // if user doesn't have all permissons about target table, return false.
        if (!$this->hasPermissionTable($table_name, Define::AUTHORITY_VALUES_AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return false;
        }

        // if user has all edit table, return true.
        if ($this->hasPermissionTable($table_name, Define::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL)) {
            return true;
        }

        // if id is null(for create), return true
        if (!isset($id)) {
            return true;
        }

        // else, get model using value_authoritable.
        $model = getModelName($table_name)::find($id);
        // if count > 0, return true.
        $rows = $model->getAuthoritable(Define::SYSTEM_TABLE_NAME_USER);
        if (isset($rows) && count($rows) > 0) {
            return true;
        }

        // else, get model using value_authoritable. (only that system uses organization.)
        // if count > 0, return true.
        if (System::organization_available()) {
            $rows = $model->getAuthoritable(Define::SYSTEM_TABLE_NAME_ORGANIZATION);
            if (isset($rows) && count($rows) > 0) {
                return true;
            }
        }

        // else, return false.
        return false;
    }

    /**
     * filter target model
     */
    public function filterModel($model, $table_name, $custom_view = null)
    {
        // view filter setting --------------------------------------------------
        // has $custom_view, filter
        if (isset($custom_view)) {
            foreach ($custom_view->custom_view_filters as $filter) {
                // get filter target column
                $view_filter_target = $filter->view_filter_target;
                if (is_numeric($view_filter_target)) {
                    $view_filter_target = getIndexColumnName(CustomColumn::find($view_filter_target));
                }
                $condition_value_text = $filter->view_filter_condition_value_text;
                $view_filter_condition = $filter->view_filter_condition;
                // get filter condition
                switch ($view_filter_condition) {
                    // equal
                    case Define::VIEW_COLUMN_FILTER_OPTION_EQ:
                        $model = $model->where($view_filter_target, $condition_value_text);
                        break;
                    // not equal
                    case Define::VIEW_COLUMN_FILTER_OPTION_NE:
                        $model = $model->where($view_filter_target, '<>', $condition_value_text);
                        break;
                    // not null
                    case Define::VIEW_COLUMN_FILTER_OPTION_NOT_NULL:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NOT_NULL:
                    case Define::VIEW_COLUMN_FILTER_OPTION_USER_NOT_NULL:
                        $model = $model->whereNotNull($view_filter_target);
                        break;
                    // null
                    case Define::VIEW_COLUMN_FILTER_OPTION_NULL:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NULL:
                    case Define::VIEW_COLUMN_FILTER_OPTION_USER_NULL:
                        $model = $model->whereNull($view_filter_target);
                        break;
                    
                    // for date --------------------------------------------------
                    // date equal day
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_ON:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_YESTERDAY:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_TODAY:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_TOMORROW:
                        // get target day
                        switch ($view_filter_condition) {
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_ON:
                                $value_day = Carbon::parse($condition_value_text);
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_YESTERDAY:
                                $value_day = Carbon::yesterday();
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_TODAY:
                                $value_day = Carbon::today();
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_TOMORROW:
                                $value_day = Carbon::tomorow();
                                break;
                        }
                        $model = $model->whereDate($view_filter_target, $value_day);
                        break;
                        
                    // date equal month
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_THIS_MONTH:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_MONTH:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_MONTH:
                        // get target month
                        switch ($view_filter_condition) {
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_THIS_MONTH:
                                $value_day = new Carbon('first day of this month');
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_MONTH:
                                $value_day = new Carbon('first day of last month');
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_MONTH:
                                $value_day = new Carbon('first day of next month');
                                break;
                        }
                        $model = $model->whereMonth($view_filter_target, $value_day);
                        break;
                        
                    // date equal year
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_THIS_YEAR:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_YEAR:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_YEAR:
                        // get target year
                        switch ($view_filter_condition) {
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_THIS_YEAR:
                                $value_day = new Carbon('first day of this year');
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_YEAR:
                                $value_day = new Carbon('first day of last year');
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_YEAR:
                                $value_day = new Carbon('first day of next year');
                                break;
                        }
                        $model = $model->whereYear($view_filter_target, $value_day);
                        break;
                        
                    // date and X days before or after
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_AFTER:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_AFTER:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_BEFORE:
                    case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_BEFORE:
                        $today = Carbon::today();
                        // get target day and where mark
                        switch ($view_filter_condition) {
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_AFTER:
                                $target_day = $today->addDay(-1 * intval($condition_value_text));
                                $mark = ">=";
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_AFTER:
                                $target_day = $today->addDay(intval($condition_value_text));
                                $mark = ">=";
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_BEFORE:
                                $target_day = $today->addDay(-1 * intval($condition_value_text));
                                $mark = "<=";
                                break;
                            case Define::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_BEFORE:
                                $target_day = $today->addDay(intval($condition_value_text));
                                $mark = "<=";
                                break;
                        }
                        $model = $model->whereDate($view_filter_target, $mark, $target_day);
                        break;
                        
                    // for user --------------------------------------------------
                    case Define::VIEW_COLUMN_FILTER_OPTION_USER_EQ_USER:
                        $model = $model->where($view_filter_target, Admin::user()->base_user()->id);
                        break;
                    case Define::VIEW_COLUMN_FILTER_OPTION_USER_NE_USER:
                        $model = $model->where($view_filter_target, '<>', Admin::user()->base_user()->id);
                           
                }
            }
        }

        // system filter(using system authority) --------------------------------------------------
        // if user has all edit table, return. (nothing doing)
        if ($this->hasPermissionTable($table_name, Define::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL)) {
            return $model;
        }

        // if user has edit or view table
        if (Admin::user()->hasPermissionTable($table_name, Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            // get only has authority
            $model = $model
                 ->whereHas('value_authoritable_users', function ($q) {
                     $q->where('related_id', $this->base_user_id);
                 })->orWhereHas('value_authoritable_organizations', function ($q) {
                     $q->whereIn('related_id', $this->getOrganizationIds())
                    ;
                 });
        }
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
        getModelName(Define::SYSTEM_TABLE_NAME_ORGANIZATION);
        $db_table_name_organization = getDBTableName(Define::SYSTEM_TABLE_NAME_ORGANIZATION);
        $db_table_name_pivot = getRelationNamebyObjs(Define::SYSTEM_TABLE_NAME_ORGANIZATION, Define::SYSTEM_TABLE_NAME_USER);
        $ids = DB::table($db_table_name_organization.' AS o1')
           ->leftJoin($db_table_name_organization.' AS o2', 'o2.parent_id', '=', 'o1.id')
           ->leftJoin($db_table_name_organization.' AS o3', 'o3.parent_id', '=', 'o3.id')
           ->leftJoin($db_table_name_pivot.' AS ou1', 'ou1.parent_id', '=', 'o1.id')
           ->leftJoin($db_table_name_pivot.' AS ou2', 'ou2.parent_id', '=', 'o2.id')
           ->leftJoin($db_table_name_pivot.' AS ou3', 'ou3.parent_id', '=', 'o3.id')
           ->orWhere('ou1.child_id', Admin::user()->base_user_id)
           ->orWhere('ou2.child_id', Admin::user()->base_user_id)
           ->orWhere('ou3.child_id', Admin::user()->base_user_id)
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
            AuthorityType::SYSTEM()->toString() => $permission_system_auths,
            AuthorityType::TABLE()->toString() => $permission_tables]);
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
                ->join(Define::SYSTEM_TABLE_NAME_SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.authority_id')
                ->join('custom_tables AS c', 'c.id', 'sa.morph_id')
                ->where('morph_type', AuthorityType::TABLE())
                ;
            // if $i == 0, then search as user
            if ($i == 0) {
                $query = $query->where('related_type', Define::SYSTEM_TABLE_NAME_USER)
                    ->where('related_id', Admin::user()->base_user_id);
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
            ->join(Define::SYSTEM_TABLE_NAME_SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.authority_id')
            ->where('morph_type', AuthorityType::SYSTEM())
            ->where(function ($query) use ($organization_ids) {
                $query->orWhere(function ($query) {
                    $query->where('related_type', Define::SYSTEM_TABLE_NAME_USER)
                    ->where('related_id', Admin::user()->base_user_id);
                });
                $query->orWhere(function ($query) use ($organization_ids) {
                    $query->where('related_type', Define::SYSTEM_TABLE_NAME_ORGANIZATION)
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
