<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Services\DynamicDBHelper;
use Encore\Admin\Facades\Admin;

getCustomTableTrait();

class CustomTable extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\CustomTableDynamicTrait; // CustomTableDynamicTrait:Dynamic Creation trait it defines relationship.
    use Traits\AutoSUuidTrait;
    use Traits\TemplateTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $casts = ['options' => 'json'];
    protected $guarded = ['id', 'suuid', 'system_flg'];

    public static $templateItems = [
        'excepts' => ['suuid'],
        'uniqueKeys' => ['table_name'],
        'langs' => [
            'keys' => ['table_name'],
            'values' => ['table_view_name', 'description'],
        ],
        'children' =>[
            'custom_columns' => CustomColumn::class,
        ],
        'ignoreImportChildren' => ['custom_columns'],
    ];

    public function custom_columns()
    {
        return $this->hasMany(CustomColumn::class, 'custom_table_id')
            ->orderBy('order');
    }
    public function custom_views()
    {
        return $this->hasMany(CustomView::class, 'custom_table_id')
            ->orderBy('view_type')
            ->orderBy('id');
    }
    public function custom_forms()
    {
        return $this->hasMany(CustomForm::class, 'custom_table_id');
    }
    public function custom_relations()
    {
        return $this->hasMany(CustomRelation::class, 'parent_custom_table_id');
    }
    
    public function child_custom_relations()
    {
        return $this->hasMany(CustomRelation::class, 'child_custom_table_id');
    }
    
    public function from_custom_copies()
    {
        return $this->hasMany(CustomCopy::class, 'from_custom_table_id');
    }
    
    public function custom_form_block_target_tables()
    {
        return $this->hasMany(CustomFormBlock::class, 'form_block_target_table_id');
    }

    public function getSelectedItems()
    {
        $raw = "json_unquote(options->'$.select_target_table')";
        return CustomColumn::where(\DB::raw($raw), $this->id)
            ->get();
    }

    public function scopeSearchEnabled($query)
    {
        return $query->whereIn('options->search_enabled', [1, "1", true]);
    }

    public function getSelectTables()
    {
        $list = $this->custom_columns->mapWithKeys(function ($item) {
            $key = $item->getIndexColumnName();
            $val = array_get($item->options, 'select_target_table');
            return [$key => (is_numeric($val)? intval($val): null)];
        });
        $list = $list->filter()->toArray();
        return $list;
    }

    public function getSelectTableColumns()
    {
        return $this->custom_columns->filter(function ($item) {
            return null != array_get($item->options, 'select_target_table');
        })->mapWithKeys(function ($item) {
            $key = $item->getIndexColumnName();
            return [$key => $item];
        });
    }

    public function getSelectedTables()
    {
        $raw = "json_unquote(options->'$.select_target_table')";
        return CustomColumn::where(\DB::raw($raw), $this->id)
            ->get()
            ->mapWithKeys(function ($item) {
                $key = $item->getIndexColumnName();
                return [$key => $item->custom_table_id];
            })->filter()->toArray();
    }

    public function getSelectedTableColumns()
    {
        $raw = "json_unquote(options->'$.select_target_table')";
        return CustomColumn::where(\DB::raw($raw), $this->id)
            ->get()
            ->mapWithKeys(function ($item) {
                $key = $item->getIndexColumnName();
                return [$key => $item];
            })->filter();
    }

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    
    /**
     * Delete children items
     */
    public function deletingChildren()
    {
        foreach ($this->custom_columns as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_forms as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_form_block_target_tables as $item) {
            $item->deletingChildren();
        }
    }

    protected static function boot()
    {
        parent::boot();
        
        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
            
            $model->custom_form_block_target_tables()->delete();
            $model->child_custom_relations()->delete();
            $model->custom_forms()->delete();
            $model->custom_columns()->delete();
            $model->custom_relations()->delete();

            // delete menu
            Menu::where('menu_type', MenuType::TABLE)->where('menu_target', $model->id)->delete();
        });
    }

    /**
     * get CustomTable by url
     */
    public static function findByEndpoint($endpoint = null, $withs = [])
    {
        // get table info
        if (!isset($endpoint)) {
            $tableKey = app('request')->route()->parameter('tableKey');
            if (!isset($tableKey)) {
                abort(404);
            }
        } else {
            $tableKey = $endpoint;
        }

        $custom_table = static::getEloquent($tableKey);
        if (!isset($custom_table)) {
            abort(404);
        }

        return $custom_table;
    }

    /**
     * get custom table eloquent.
     * @param mixed $obj id, table_name, CustomTable object, CustomValue object.
     */
    public static function getEloquent($obj, $withs = [])
    {
        if ($obj instanceof CustomTable) {
            return static::withLoad($obj, $withs);
        } elseif ($obj instanceof CustomColumn) {
            return static::withLoad($obj->custom_table, $withs);
        } elseif ($obj instanceof CustomValue) {
            return static::withLoad($obj->custom_table, $withs);
        }

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
            $query_key = 'id';
        } elseif (is_string($obj)) {
            $query_key = 'table_name';
        }
        if (isset($query_key)) {
            // get table
            $obj = static::allRecords(function ($table) use ($query_key, $obj) {
                return array_get($table, $query_key) == $obj;
            })->first();
            if (!isset($obj)) {
                return null;
            }
        }

        return static::withLoad($obj, $withs);
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
        if (!\Exment::user()->hasPermission(Permission::CUSTOM_TABLE)) {
            // get tables has custom_table permission.
            $permission_tables = \Exment::user()->allHasPermissionTables(Permission::CUSTOM_TABLE);
            $permission_table_ids = $permission_tables->map(function ($permission_table) {
                return array_get($permission_table, 'id');
            });
            // filter id;
            $model = $model->whereIn('id', $permission_table_ids);
        }

        // add default order
        $model = $model->orderBy('order', 'asc');

        if ($options['getModel']) {
            return $model->get();
        }
        return $model;
    }

    protected static function getWiths($withs)
    {
        if (is_array($withs)) {
            return $withs;
        }
        if ($withs === true) {
            return ['custom_columns'];
        }
        return [];
    }
    
    /**
     * set lazy load and return
     */
    protected static function withLoad($obj, $withs = [])
    {
        $withs = static::getWiths($withs);
        if (count($withs) > 0) {
            $obj->load($withs);
        }
        return $obj;
    }

    protected function importSetValue(&$json, $options = [])
    {
        $system_flg = array_get($options, 'system_flg', false);
        $table_system_flg = array_get($json, 'system_flg');
        $this->system_flg = ($system_flg && (is_null($table_system_flg) || $table_system_flg != 0));

        // set showlist_flg
        if (!array_has($json, 'showlist_flg')) {
            $this->showlist_flg = true;
        } elseif (boolval(array_get($json, 'showlist_flg'))) {
            $this->showlist_flg = true;
        } else {
            $this->showlist_flg = false;
        }

        // return expects array
        return ['system_flg', 'showlist_flg'];
    }

    protected function importSaved($json, $options = [])
    {
        $this->createTable();
    }
    
    /**
     * search value
     */
    public function searchValue($q, $options = [])
    {
        $options = array_merge(
            [
                'isLike' => true,
                'maxCount' => 5,
                'paginate' => false,
                'makeHidden' => false,
            ],
            $options
        );
        extract($options);

        $search_columns = $this->getSearchEnabledColumns();

        $data = [];
        $value = ($isLike ? '%' : '') . $q . ($isLike ? '%' : '');
        $mark = ($isLike ? 'LIKE' : '=');

        // get data
        $query = getModelName($this)
            ::where(function ($wherequery) use ($search_columns, $mark, $value) {
                foreach ($search_columns as $search_column) {
                    $wherequery->orWhere($search_column->getIndexColumnName(), $mark, $value);
                }
            });
        
        // return as paginate
        if ($paginate) {
            $paginates = $query->paginate($maxCount);
            if (boolval($makeHidden)) {
                $data = $paginates->makeHidden($this->getMakeHiddenArray());
                $paginates->data = $data;
            }

            return $paginates;
        }

        // return default
        return $query
            ->take($maxCount)
            ->get();
    }

    /**
     * Get search-enabled columns.
     */
    public function getSearchEnabledColumns()
    {
        return $this->custom_columns()
            ->indexEnabled()
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
        if (boolval(System::requestSession($key))) {
            return;
        }

        // CREATE TABLE from custom value table.
        if (hasTable($table_name)) {
            return;
        }

        DynamicDBHelper::createValueTable($table_name);
        System::requestSession($key, 1);
    }
    
    /**
     * Get index column name
     * @param string|CustomTable|array $obj
     * @return string
     */
    public function getIndexColumnName($column_name)
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
     * *"$this" is the table targeted on options.
     * *"$display_table" is the table user shows on display.
     *
     * @param $selected_value the value that already selected.
     * @param CustomTable $display_table Information on the table displayed on the screen
     * @param boolean $all is show all data. for system role, it's true.
     */
    public function getOptions($selected_value = null, $display_table = null, $all = false, $showMessage_ifDeny = false)
    {
        if (is_null($display_table)) {
            $display_table = $this;
        }
        $table_name = $this->table_name;
        $display_table = CustomTable::getEloquent($display_table);
        // check table permission. if not exists, show admin_warning
        if (!in_array($table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION]) && !$this->hasPermission()) {
            if ($showMessage_ifDeny) {
                admin_warning(trans('admin.deny'), sprintf(exmtrans('custom_column.help.select_table_deny'), $display_table->table_view_name));
            }
            return [];
        }

        // get query.
        // if org
        if (in_array($table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION]) && in_array($display_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
            $query = $this->getValueModel();
        }
        // if $table_name is user or organization, get from getRoleUserOrOrg
        elseif ($table_name == SystemTableName::USER && !$all) {
            $query = AuthUserOrgHelper::getRoleUserQuery($display_table);
        } elseif ($table_name == SystemTableName::ORGANIZATION && !$all) {
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
        return admin_urls("webapi", 'data', array_get($this, 'table_name'), "search");
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
    public function getColumnsSelectOptions($append_table = false, $index_enabled_only = false, $include_parent = false, $include_child = false)
    {
        $options = [];
        
        $this->setColumnOptions(
        
            $options,
        
            $this->custom_columns,
        
            $index_enabled_only,
            true,
        
            true,
        
            $append_table,
        
            $this->id
        
        );

        if ($include_parent) {
            ///// get child table columns
            $relations = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->id)->get();
            foreach ($relations as $rel) {
                $parent = array_get($rel, 'parent_custom_table');
                $parent_id = array_get($rel, 'parent_custom_table_id');
                $tablename = array_get($parent, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $parent->custom_columns,
                    $index_enabled_only,
                    true,
                    true,
                    $append_table,
                    $parent_id,
                    $tablename
                );
            }
            ///// get select table columns
            $select_table_columns = $this->getSelectTableColumns();
            foreach ($select_table_columns as $select_table_column) {
                $custom_table = $select_table_column->column_item->getSelectTable();
                $tablename = array_get($custom_table, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $custom_table->custom_columns,
                    $index_enabled_only,
                    true,
                    true,
                    $append_table,
                    $custom_table->id,
                    $tablename
                );
            }
        }
        if ($include_child) {
            ///// get child table columns
            $relations = CustomRelation::with('child_custom_table')->where('parent_custom_table_id', $this->id)->get();
            foreach ($relations as $rel) {
                $child = array_get($rel, 'child_custom_table');
                $child_id = array_get($rel, 'child_custom_table_id');
                $tablename = array_get($child, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $child->custom_columns,
                    $index_enabled_only,
                    true,
                    false,
                    $append_table,
                    $child_id,
                    $tablename
                );
            }
            ///// get selected table columns
            $selected_table_columns = $this->getSelectedTableColumns();
            foreach ($selected_table_columns as $selected_table_column) {
                $custom_table = $selected_table_column->custom_table;
                $tablename = array_get($custom_table, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $custom_table->custom_columns,
                    $index_enabled_only,
                    true,
                    true,
                    $append_table,
                    $custom_table->id,
                    $tablename
                );
            }
        }
    
        return $options;
    }
    protected function setColumnOptions(
        &$options,
        $custom_columns,
        $index_enabled_only,
        $include_system,
        $include_parent,
        $append_table,
        $table_id,
        $table_name = null
    ) {
        if ($include_system) {
            /// get system columns
            foreach (SystemColumn::getOptions(['header' => true]) as $option) {
                $key = $this->getOptionKey(array_get($option, 'name'), $append_table, $table_id);
                $value = exmtrans('common.'.array_get($option, 'name'));
                if (isset($table_name)) {
                    $value = $table_name . ' : ' . $value;
                }
                $options[$key] = $value;
            }
        }
        if ($include_parent) {
            $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $table_id)->first();
            ///// if this table is child relation(1:n), add parent table
            if (isset($relation)) {
                $key = $this->getOptionKey('parent_id', $append_table, $table_id);
                $value = array_get($relation, 'parent_custom_table.table_view_name');
                if (isset($table_name)) {
                    $value = $table_name . ' : ' . $value;
                }
                $options[$key] = $value;
            }
        }
        foreach ($custom_columns as $custom_column) {
            // if $index_enabled_only = true and options.index_enabled_only is false, continue
            if ($index_enabled_only && !$custom_column->indexEnabled()) {
                continue;
            }
            $key = $this->getOptionKey(array_get($custom_column, 'id'), $append_table, $table_id);
            $value = array_get($custom_column, 'column_view_name');
            if (isset($table_name)) {
                $value = $table_name . ' : ' . $value;
            }
            $options[$key] = $value;
        }
        if ($include_system) {
            ///// get system columns
            foreach (SystemColumn::getOptions(['footer' => true]) as $option) {
                $key = $this->getOptionKey(array_get($option, 'name'), $append_table, $table_id);
                $value = exmtrans('common.'.array_get($option, 'name'));
                if (isset($table_name)) {
                    $value = $table_name . ' : ' . $value;
                }
                $options[$key] = $value;
            }
        }
    }
    
    protected function getOptionKey($key, $append_table = true, $table_name = null)
    {
        if ($append_table) {
            return ($table_name?? $this->id) . '-' . $key;
        } else {
            return $key;
        }
    }

    /**
     * get number columns select options. It contains integer, decimal, currency columns.
     * @param array|CustomTable $table
     * @param $selected_value
     */
    public function getSummaryColumnsSelectOptions()
    {
        $options = [];
        
        /// get system columns for summary
        foreach (SystemColumn::getOptions(['summary' => true]) as $option) {
            $options[$this->getOptionKey(array_get($option, 'name'))] = exmtrans('common.'.array_get($option, 'name'));
        }

        ///// get table columns
        $custom_columns = $this->custom_columns;
        foreach ($custom_columns as $option) {
            $column_type = array_get($option, 'column_type');
            if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                $options[$this->getOptionKey(array_get($option, 'id'))] = array_get($option, 'column_view_name');
            }
        }
        ///// get child table columns for summary
        $relations = CustomRelation::with('child_custom_table')->where('parent_custom_table_id', $this->id)->get();
        foreach ($relations as $rel) {
            $child = array_get($rel, 'child_custom_table');
            $tableid = array_get($child, 'id');
            $tablename = array_get($child, 'table_view_name');
            /// get system columns for summary
            foreach (SystemColumn::getOptions(['summary' => true]) as $option) {
                $options[$this->getOptionKey(array_get($option, 'name'), true, $tableid)]
                    = $tablename . ' : ' . exmtrans('common.'.array_get($option, 'name'));
            }
            $child_columns = $child->custom_columns;
            foreach ($child_columns as $option) {
                $column_type = array_get($option, 'column_type');
                if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                    $options[$this->getOptionKey(array_get($option, 'id'), true, $tableid)]
                        = $tablename . ' : ' . array_get($option, 'column_view_name');
                }
            }
        }
        ///// get selected table columns
        $selected_table_columns = $this->getSelectedTableColumns();
        foreach ($selected_table_columns as $selected_table_column) {
            $custom_table = $selected_table_column->custom_table;
            $tablename = array_get($custom_table, 'table_view_name');
            /// get system columns for summary
            foreach (SystemColumn::getOptions(['summary' => true]) as $option) {
                $options[$this->getOptionKey(array_get($option, 'name'), true, $custom_table->id)]
                    = $tablename . ' : ' . exmtrans('common.'.array_get($option, 'name'));
            }
            foreach ($custom_table->custom_columns as $option) {
                $column_type = array_get($option, 'column_type');
                if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                    $options[$this->getOptionKey(array_get($option, 'id'), true, $custom_table->id)]
                        = $tablename . ' : ' . array_get($option, 'column_view_name');
                }
            }
        }
    
        return $options;
    }
        
    public function getValueModel()
    {
        $modelname = getModelName($this);
        $model = new $modelname;

        return $model;
    }

    /**
     * get array for "makeHidden" function
     */
    public function getMakeHiddenArray()
    {
        return $this->getSearchEnabledColumns()->map(function ($columns) {
            return $columns->getIndexColumnName();
        })->toArray();
    }

    // --------------------------------------------------
    // Permission
    // --------------------------------------------------
    /**
     * whether login user has permission. target is table
     */
    public function hasPermission($role_key = Permission::AVAILABLE_VIEW_CUSTOM_VALUE)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        $table_name = $this->table_name;
        if (!is_array($role_key)) {
            $role_key = [$role_key];
        }

        $user = \Exment::user();
        if (!isset($user)) {
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
     * Whether login user has permission about target id data.
     */
    public function hasPermissionData($id)
    {
        return $this->_hasPermissionData($id, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE);
    }

    /**
     * Whether login user has edit permission about target id data.
     */
    public function hasPermissionEditData($id)
    {
        return $this->_hasPermissionData($id, Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
    }

    /**
     * Whether login user has permission about target id data. (protected function)
     */
    protected function _hasPermissionData($id, $role)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        // if user doesn't have all permissons about target table, return false.
        if (!$this->hasPermission($role)) {
            return false;
        }

        // if user has all edit table, return true.
        if ($this->hasPermission(Permission::AVAILABLE_ALL_CUSTOM_VALUE)) {
            return true;
        }

        // if id is null(for create), return true
        if (!isset($id)) {
            return true;
        }

        if (is_numeric($id)) {
            $model = getModelName($this)::find($id);
        } else {
            $model = $id;
        }

        if (!isset($model)) {
            return false;
        }

        // else, get model using value_authoritable.
        // if count > 0, return true.
        $rows = $model->getAuthoritable(SystemTableName::USER);
        if ($this->checkPermissionWithPivot($rows, $role)) {
            return true;
        }

        // else, get model using value_authoritable. (only that system uses organization.)
        // if count > 0, return true.
        if (System::organization_available()) {
            $rows = $model->getAuthoritable(SystemTableName::ORGANIZATION);
            if ($this->checkPermissionWithPivot($rows, $role)) {
                return true;
            }
        }

        // else, return false.
        return false;
    }

    /**
     * check permission with pivot
     */
    protected function checkPermissionWithPivot($rows, $role_key)
    {
        if (!isset($rows) || count($rows) == 0) {
            return false;
        }

        foreach ($rows as $row) {
            // get role
            $role = Role::getEloquent(array_get($row, 'pivot.role_id'));

            // if role type is system, and has key
            $permissions = $role->permissions;
            if (array_keys_exists($role_key, $permissions)) {
                return true;
            }
        }

        return false;
    }

    /**
     *
     */
    public function allUserAccessable()
    {
        return boolval($this->getOption('all_user_editable_flg'))
            || boolval($this->getOption('all_user_viewable_flg'))
            || boolval($this->getOption('all_user_accessable_flg'));
    }
}
