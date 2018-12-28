<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\RoleValue;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\DB;

trait CustomTableTrait
{
    /**
     * Find record using table name
     * @param mixed $model_name
     * @return mixed
     */
    public static function findByName($model_name, $with_custom_columns = false)
    {
        $query = static::where('table_name', $model_name);
        if ($with_custom_columns) {
            $query = $query->with('custom_columns');
        }
        return $query->first();
    }

    /**
     * Find record using database table name
     * @param mixed $table_name
     * @return mixed
     */
    public static function findByDBTableName($db_table_name, $with_custom_columns = false)
    {
        $query = static::where('suuid', preg_replace('/^exm__/', '', $db_table_name));
        if ($with_custom_columns) {
            $query = $query->with('custom_columns');
        }
        return $query->first();
    }

    /**
     * get custom table eloquent.
     * @param mixed $obj id, table_name, CustomTable object, CustomValue object.
     */
    public static function getEloquent($obj)
    {
        if ($obj instanceof \stdClass) {
            $obj = (array)$obj;
        }
        // get id or array value
        if (is_array($obj)) {
            // get id or table_name
            if (array_key_value_exists('id', $obj)) {
                $obj = array_get($obj, 'id');
            } elseif (array_key_value_exists('table_name', $obj)) {
                $obj = array_get($obj, 'table_name');
            } else {
                return null;
            }
        }

        // get eloquent model
        if (is_numeric($obj)) {
            $obj = static::find($obj);
        } elseif (is_string($obj)) {
            $obj = static::findByName($obj);
        } elseif (is_array($obj)) {
            $obj = static::findByName(array_get($obj, 'table_name'));
        } elseif ($obj instanceof CustomTable) {
            // nothing
        }elseif ($obj instanceof CustomColumn) {
            $obj = $obj->custom_table;
        }elseif ($obj instanceof CustomValue) {
            $obj = $obj->custom_table;
        }
        return $obj;
    }

    /**
     * get table list.
     * But filter these:
     *     Get only has role
     *     showlist_flg is true
     */
    public static function filterList($model = null, $options = [])
    {
        $options = array_merge(
            [
                'getModel' => true
            ],
            $options
        );
        if (!isset($model)) {
            $model = new self;
        }
        $model = $model->where('showlist_flg', true);

        // if not exists, filter model using permission
        if (!Admin::user()->hasPermission(RoleValue::CUSTOM_TABLE)) {
            // get tables has custom_table permission.
            $permission_tables = Admin::user()->allHasPermissionTables(RoleValue::CUSTOM_TABLE);
            $permission_table_ids = $permission_tables->map(function ($permission_table) {
                return array_get($permission_table, 'id');
            });
            // filter id;
            $model = $model->whereIn('id', $permission_table_ids);
        }

        if ($options['getModel']) {
            return $model->get();
        }
        return $model;
    }

    /**
     * whether has permission. target is table
     */
    public function hasPermission($role_key = RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE){
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        $table_name = $this->table_name;
        if (!is_array($role_key)) {
            $role_key = [$role_key];
        }

        $user = \Exment::user();
        if(!isset($user)){
            return false;
        }
        
        $permissions = $user->allPermissions();
        foreach ($permissions as $permission) {
            // if role type is system, and has key
            if (RoleType::SYSTEM == $permission->getRoleType()
                && array_keys_exists($role_key, $permission->getPermissionDetails())) {
                return true;
            }

            // if role type is table, and match table name
            elseif (RoleType::TABLE == $permission->getRoleType() && $permission->getTableName() == $table_name) {
                // if user has role
                if (array_keys_exists($role_key, $permission->getPermissionDetails())) {
                    return true;
                }
            }
        }

        return false;
    }
    
    /**
     * Whether user has permission about target id data.
     */
    public function hasPermissionData($id)
    {
        return $this->_hasPermissionData($id, RoleValue::AVAILABLE_ACCESS_CUSTOM_VALUE);
    }

    /**
     * Whether user has edit permission about target id data.
     */
    public function hasPermissionEditData($id)
    {
        return $this->_hasPermissionData($id, RoleValue::AVAILABLE_EDIT_CUSTOM_VALUE);
    }

    protected function _hasPermissionData($id, $role){
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        // if user doesn't have all permissons about target table, return false.
        if (!$this->hasPermission($role)) {
            return false;
        }

        // if user has all edit table, return true.
        if ($this->hasPermission(RoleValue::AVAILABLE_ALL_CUSTOM_VALUE)) {
            return true;
        }

        // if id is null(for create), return true
        if (!isset($id)) {
            return true;
        }

        if(is_numeric($id)){
            $model = getModelName($this)::find($id);
        }else{
            $model = $id;
        }

        if (!isset($model)) {
            return false;
        }

        // else, get model using value_authoritable.
        // if count > 0, return true.
        $rows = $model->getAuthoritable(SystemTableName::USER);
        if (isset($rows) && count($rows) > 0) {
            return true;
        }

        // else, get model using value_authoritable. (only that system uses organization.)
        // if count > 0, return true.
        if (System::organization_available()) {
            $rows = $model->getAuthoritable(SystemTableName::ORGANIZATION);
            if (isset($rows) && count($rows) > 0) {
                return true;
            }
        }

        // else, return false.
        return false;
    }

    /**
     * Get search-enabled columns.
     */
    public function getSearchEnabledColumns()
    {
        return $this->custom_columns()
            ->whereIn('options->search_enabled', [1, "1"])
            ->get();
    }

    /**
     * Create Table on Database.
     *
     * @return void
     */
    public function createTable()
    {
        $table_name = getDBTableName($this);
        // if not null
        if (!isset($table_name)) {
            throw new Exception('table name is not found. please tell system administrator.');
        }

        // check already execute
        $key = 'create_table.'.$table_name;
        if (boolval(getRequestSession($key))) {
            return;
        }

        // CREATE TABLE from custom value table.
        $db = DB::connection();
        $db->statement("CREATE TABLE IF NOT EXISTS ".$table_name." LIKE custom_values");
        
        setRequestSession($key, 1);
    }
    
    /**
     * Get index column name
     * @param string|CustomTable|array $obj
     * @return string
     */
    function getIndexColumnName($column_name)
    {
        // get column eloquent
        $column = CustomColumn::getEloquent($column_name, $this);
        // return column name
        return $column->getIndexColumnName();
    }
    
    /**
     * get options for select, multipleselect.
     * But if options count > 100, use ajax, so only one record.
     *
     * @param array|CustomTable $table
     * @param $selected_value
     */
    public function isGetOptions()
    {
        // get count table.
        $count = $this->getOptionsQuery()::count();
        // when count > 0, create option only value.
        return $count <= 100;
    }

    /**
     * get options for select, multipleselect.
     * But if options count > 100, use ajax, so only one record.
     *
     * @param $selected_value the value that already selected.
     * @param CustomTable $display_table Information on the table displayed on the screen
     * @param boolean $all is show all data. for system role, it's true.
     */
    public function getOptions($selected_value = null, $display_table = null, $all = false)
    {
        if (is_null($display_table)) {
            $display_table = $this;
        }
        $table_name = $this->table_name;

        // get query.
        // if org
        if(in_array($table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION]) && in_array($display_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])){
            $query = $this->getValueModel();
        }
        // if $table_name is user or organization, get from getRoleUserOrOrg
        elseif ($table_name ==SystemTableName::USER && !$all) {
            $query = AuthUserOrgHelper::getRoleUserQuery($display_table);
        }
        elseif ($table_name ==SystemTableName::ORGANIZATION && !$all) {
            $query = AuthUserOrgHelper::getRoleOrganizationQuery($display_table);
        } else {
            $query = $this->getOptionsQuery();
        }

        // when count > 100, create option only value.
        if (!$this->isGetOptions()) {
            if (!isset($selected_value)) {
                return [];
            }
            $item = getModelName($this)::find($selected_value);

            if ($item) {
                // check whether $item is multiple value.
                if ($item instanceof Collection) {
                    $ret = [];
                    foreach ($item as $i) {
                        $ret[$i->id] = $i->label;
                    }
                    return $ret;
                }
                return [$item->id => $item->label];
            } else {
                return [];
            }
        }
        return $query->get()->pluck("label", "id");
    }

    /**
     * get ajax url for options for select, multipleselect.
     *
     * @param array|CustomTable $table
     * @param $value
     */
    public function getOptionAjaxUrl()
    {
        // get count table.
        $count = $this->getOptionsQuery()::count();
        // when count > 0, create option only value.
        if ($count <= 100) {
            return null;
        }
        return admin_base_paths("webapi", 'data', array_get($this, 'table_name'), "query");
    }

    /**
     * getOptionsQuery. this function uses for count, get, ...
     */
    protected function getOptionsQuery()
    {
        // get model
        $model = $this->getValueModel();

        // filter model
        $model = Admin::user()->filterModel($model, $this);
        return $model;
    }

    /**
     * get columns select options. It contains system column(ex. id, suuid, created_at, updated_at), and table columns.
     * @param array|CustomTable $table
     * @param $selected_value
     */
    public function getColumnsSelectOptions($search_enabled_only = false)
    {
        $options = [];
        
        ///// get system columns
        foreach (ViewColumnType::SYSTEM_OPTIONS() as $option) {
            // not header, continue
            if (!boolval(array_get($option, 'header'))) {
                continue;
            }
            $options[array_get($option, 'name')] = exmtrans('common.'.array_get($option, 'name'));
        }

        ///// if this table is child relation(1:n), add parent table
        $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->id)->first();
        if (isset($relation)) {
            $options['parent_id'] = array_get($relation, 'parent_custom_table.table_view_name');
        }

        ///// get table columns
        $custom_columns = $this->custom_columns;
        foreach ($custom_columns as $option) {
            // if $search_enabled_only = true and options.search_enabled is false, continue
            if ($search_enabled_only && !boolval(array_get($option, 'options.search_enabled'))) {
                continue;
            }
            $options[array_get($option, 'id')] = array_get($option, 'column_view_name');
        }
        ///// get system columns
        foreach (ViewColumnType::SYSTEM_OPTIONS() as $option) {
            // not footer, continue
            if (!boolval(array_get($option, 'footer'))) {
                continue;
            }
            $options[array_get($option, 'name')] = exmtrans('common.'.array_get($option, 'name'));
        }

        if (!$search_enabled_only) {
            ///// get child table columns for summary
            $relations = CustomRelation::with('child_custom_table')->where('parent_custom_table_id', $this->id)->get();
            foreach ($relations as $rel) {
                $child = array_get($rel, 'child_custom_table');
                $tableid = array_get($child, 'id');
                $tablename = array_get($child, 'table_view_name');
                $child_columns = $child->custom_columns;
                foreach ($child_columns as $option) {
                    switch(array_get($option, 'column_type')) {
                        case ColumnType::INTEGER:
                        case ColumnType::DECIMAL:
                        case ColumnType::CURRENCY:
                            $options[$tableid . '_' . array_get($option, 'id')] = $tablename . ' : ' . array_get($option, 'column_view_name');
                            break;
                    }
                }
            }
        }
    
        return $options;
    }
    
    public function getValueModel(){
        $modelname = getModelName($this);
        $model = new $modelname;

        return $model;
    }
}
