<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Input;

class CustomTable extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\AutoSUuidTrait;
    use Traits\TemplateTrait;
    use Traits\ColumnOptionQueryTrait;

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
            'custom_column_multisettings' => CustomColumnMulti::class,
        ],
        'ignoreImportChildren' => ['custom_columns', 'custom_column_multisettings'],
    ];

    public function custom_columns()
    {
        return $this->hasMany(CustomColumn::class, 'custom_table_id');
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
 
    public function custom_operations()
    {
        return $this->hasMany(CustomOperation::class, 'custom_table_id');
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
    
    public function notifies()
    {
        return $this->hasMany(Notify::class, 'custom_table_id');
    }
    
    public function custom_form_block_target_tables()
    {
        return $this->hasMany(CustomFormBlock::class, 'form_block_target_table_id');
    }

    public function custom_column_multisettings()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id');
    }

    public function multi_uniques()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', 1);
    }

    public function table_labels()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', 2);
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return boolval($this->system_flg);
    }

    /**
     * Get Columns where select_target_table's id is this table.
     *
     * @return void
     */
    public function getSelectedItems()
    {
        return CustomColumn::where('options->select_target_table', $this->id)
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

    /**
     * Get this table's select table's key-value collection.
     * Key is column index name.
     * Value is custom column.
     * Filter is select_target_table
     *
     * @return Collection
     */
    public function getSelectTableColumns()
    {
        return $this->custom_columns->filter(function ($item) {
            return null != array_get($item->options, 'select_target_table');
        })->mapWithKeys(function ($item) {
            $key = $item->getIndexColumnName();
            return [$key => $item];
        });
    }

    /**
     * Get key-value items.
     * Key is column index name.
     * Value is select_target_table's table id.
     *
     * @return array
     */
    public function getSelectedTables()
    {
        return CustomColumn::where('options->select_target_table', $this->id)
            ->get()
            ->mapWithKeys(function ($item) {
                $key = $item->getIndexColumnName();
                return [$key => $item->custom_table_id];
            })->filter()->toArray();
    }

    /**
     * Get key-value items.
     * Key is column index name.
     * Value is custom column.
     *
     * @return Collection
     */
    public function getSelectedTableColumns()
    {
        return CustomColumn::where('options->select_target_table', $this->id)
            ->get()
            ->mapWithKeys(function ($item) {
                $key = $item->getIndexColumnName();
                return [$key => $item];
            })->filter();
    }

    /**
     * get Select table's relation columns.
     * If there are two or more select_tables in the same table and they are in a parent-child relationship, parent-child relationship information is acquired.
     *
     * @return array contains parent_column, child_column, searchType
     */
    public function getSelectTableRelationColumns()
    {
        $result = [];

        $columns = $this->getSelectTableColumns();
        
        // re-loop for relation
        foreach ($columns as $column) {
            // get custom table
            $custom_table = $column->select_target_table;
            if (!isset($custom_table)) {
                continue;
            }

            // if same table, continue
            if ($this->id == $custom_table->id) {
                continue;
            }

            // get children tables
            $relations = $custom_table->getRelationTables();
            // if not exists, continue
            if (!$relations) {
                continue;
            }
            foreach ($relations as $relation) {
                $child_custom_table = array_get($relation, 'table');
                collect($columns)->filter(function ($child_column) use ($child_custom_table) {
                    return $child_column->select_target_table && $child_column->select_target_table->id == $child_custom_table->id;
                })
                ->each(function ($child_column) use ($column, $relation, &$result) {
                    $result[] = [
                        'parent_column' => $column,
                        'child_column' => $child_column,
                        'searchType' => array_get($relation, 'searchType'),
                    ];
                });
            }
        }

        return $result;
    }

    public function getMultipleUniques($custom_column = null)
    {
        return CustomColumnMulti::allRecords(function ($val) use ($custom_column) {
            if (array_get($val, 'custom_table_id') != $this->id) {
                return false;
            }

            if (!isset($custom_column)) {
                return true;
            }

            $targetid = CustomColumn::getEloquent($custom_column, $this)->id;
            foreach ([1,2,3] as $key) {
                if ($val->{'unique' . $key} == $targetid) {
                    return true;
                }
            }
            return false;
        }, false);
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
        foreach ($this->custom_views as $item) {
            $item->deletingChildren();
        }
        foreach ($this->from_custom_copies as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_form_block_target_tables as $item) {
            $item->deletingChildren();
        }
    }

    protected static function boot()
    {
        parent::boot();
        
        // add default order
        // "order" is added v1.1.0, So if called from v1.1.0, cannot excute. So checked order column
        if (System::requestSession(Define::SYSTEM_KEY_SESSION_HAS_CUSTOM_TABLE_ORDER, function () {
            return \Schema::hasColumn(static::getTableName(), 'order');
        })) {
            static::addGlobalScope(new OrderScope('order'));
        }

        static::saving(function ($model) {
            $model->prepareJson('options');
        });

        // delete event
        static::deleting(function ($model) {
            // delete custom values table
            $model->dropTable();

            // Delete items
            $model->deletingChildren();
            
            $model->custom_form_block_target_tables()->delete();
            $model->child_custom_relations()->delete();
            $model->custom_views()->delete();
            $model->custom_forms()->delete();
            $model->custom_columns()->delete();
            $model->custom_relations()->delete();

            // delete items
            Notify::where('custom_table_id', $model->id)->delete();
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
            // for command execute
            if (is_null(app('request')->route())) {
                return null;
            }

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
     * get filter and sort order from request.
     * @param $options(query string).
     * @param $addFilter.
     */
    public function getGridUrl($addFilter = false, $options = [])
    {
        $path = 'data/' . $this->table_name;

        if ($addFilter) {
            $view = array_get($options, 'view');

            if (is_null($view)) {
                $custom_view = CustomView::getDefault($this);
                $view = $custom_view->suuid;
            }

            // get page settings
            $settings = \Exment::user()->getSettingValue($path)?? '[]';
            $settings = json_decode($settings, true);

            // get view settings
            $parameters = [];
            if (isset($view) && array_key_exists($view, $settings)) {
                $parameters = array_get($settings, $view);
            }

            // merge old and current settings
            $parameters = array_merge($options, $parameters);
        }

        if (isset($parameters) && count($parameters) > 0) {
            return admin_url($path).'?'.http_build_query($parameters);
        } else {
            return admin_url($path);
        }
    }

    /**
     * save filter and sort order.
     * @param $path.
     */
    public function saveGridParameter($path)
    {
        $custom_view = CustomView::getDefault($this);

        if (is_null($custom_view)) {
            return;
        }

        $path = admin_exclusion_path($path);

        $view = $custom_view->suuid;

        $inputs = Arr::except(Input::all(), ['view', '_pjax', '_token', '_method', '_previous_', 'group_key']);

        $parameters = \Exment::user()->getSettingValue($path)?? '[]';
        $parameters = json_decode($parameters, true);

        $parameters[$view] = $inputs;

        Admin::user()->setSettingValue($path, json_encode($parameters));
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
                'getModel' => true,
                'permissions' => Permission::CUSTOM_TABLE,
                'with' => null,
                'filter' => null,
                'checkPermission' => true,
            ],
            $options
        );
        if (!isset($model)) {
            $model = new self;
        }
        $model = $model->where('showlist_flg', true);

        // if not exists, filter model using permission
        if ($options['checkPermission'] && !\Exment::user()->hasPermission(Permission::CUSTOM_TABLE)) {
            // get tables has custom_table permission.
            $permission_tables = \Exment::user()->allHasPermissionTables($options['permissions']);
            $permission_table_ids = $permission_tables->map(function ($permission_table) {
                return array_get($permission_table, 'id');
            });
            // filter id;
            $model = $model->whereIn('id', $permission_table_ids);
        }

        if (isset($options['with'])) {
            $with = is_array($options['with']) ? $options['with'] : [$options['with']];
            $model->with($with);
        }

        if (isset($options['filter'])) {
            $model = $options['filter']($model);
        }

        if ($options['getModel']) {
            return $model->get();
        }
        return $model;
    }

    /**
     * get 'with' array for get eloquent
     */
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

    public function importSaved($options = [])
    {
        $this->createTable();

        return $this;
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
                'searchColumns' => null,
                'relation' => false,
                'target_view' => null,
            ],
            $options
        );
        extract($options);

        $data = [];

        $mainQuery = $this->getValueModel()->getSearchQuery($q, $options);

        if (!isset($mainQuery)) {
            return null;
        }

        // set custom view's filter
        if (isset($target_view)) {
            $mainQuery = \Exment::user()->filterModel($mainQuery, $target_view);
        }

        // return as paginate
        if ($paginate) {
            // get data(only id)
            $paginates = $mainQuery->select('id')->paginate($maxCount);

            // set eloquent data using ids
            $ids = collect($paginates->items())->map(function ($item) {
                return $item->id;
            });

            // set pager items
            $paginates->setCollection(getModelName($this)::whereIn('id', $ids->toArray())->get());
            
            if (boolval($makeHidden)) {
                $data = $paginates->makeHidden($this->getMakeHiddenArray());
                $paginates->data = $data;
            }

            return $paginates;
        }

        // return default
        $ids = $mainQuery->select('id')->take($maxCount)->get()->pluck('id');
        return getModelName($this)
            ::whereIn('id', $ids)
            ->take($maxCount)
            ->get();
    }

    /**
     * search relation value
     */
    public function searchRelationValue($search_type, $parent_value_id, $child_table, &$options = [])
    {
        $options = array_merge(
            [
                'paginate' => false,
                'maxCount' => 5,
            ],
            $options
        );
        extract($options);
        
        $child_table = static::getEloquent($child_table);

        switch ($search_type) {
            // self table
            case SearchType::SELF:
                // set query info
                $options['listQuery'] = [
                    'id' => $parent_value_id,
                ];
                
                return [$this->getValueModel($parent_value_id)];
            // select_table(select box)
            case SearchType::SELECT_TABLE:
                // get columns for relation child to parent
                $searchColumns = $child_table->custom_columns()
                    ->where('column_type', ColumnType::SELECT_TABLE)
                    ->whereIn('options->select_target_table', [strval($this->id), intval($this->id)])
                    ->indexEnabled()
                    ->get()
                    ->map(function ($c) {
                        return $c->getIndexColumnName();
                    });

                // set query info
                if (isset($searchColumns)) {
                    $options['listQuery'] = [
                        $searchColumns->first() => $parent_value_id,
                    ];
                }

                return $child_table->searchValue($parent_value_id, [
                    'isLike' => false,
                    'paginate' => $paginate,
                    'relation' => true,
                    'searchColumns' => $searchColumns,
                    'maxCount' => $maxCount,
                ]);
            
            // one_to_many
            case SearchType::ONE_TO_MANY:
                $query = $child_table->getValueModel()
                    ->where('parent_id', $parent_value_id)
                    ->where('parent_type', $this->table_name);

                // set query info
                $options['listQuery'] = [
                    'parent_id' => $parent_value_id,
                ];

                return $paginate ? $query->paginate($maxCount) : $query->get();
            // many_to_many
            case SearchType::MANY_TO_MANY:
                $relation_name = CustomRelation::getRelationNameByTables($this, $child_table);
                // get search_table value
                // where: parent_id is value_id
                $query = $child_table->getValueModel()
                    ::join($relation_name, "$relation_name.child_id", getDBTableName($child_table).".id")
                    ->where("$relation_name.parent_id", $parent_value_id);
                    
                return $paginate ? $query->paginate($maxCount) : $query->get();
        }

        return null;
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

        \Schema::createValueTable($table_name);
        System::requestSession($key, 1);
    }

    public function dropTable()
    {
        $table_name = getDBTableName($this);
        if (!\Schema::hasTable($table_name)) {
            return;
        }
        \Schema::dropIfExists($table_name);
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
     * @param $custom_view
     */
    public function isGetOptions($options = [])
    {
        extract(array_merge(
            [
                'target_view' => null,
                'custom_column' => null,
                'notAjax' => false,
                'callQuery' => true,
            ],
            $options
        ));

        // if not ajax, return true
        if (boolval($notAjax)) {
            return true;
        }
        // if custom table option's select_load_ajax is true, return false (as ajax).
        elseif (isset($custom_column) && boolval(array_get($custom_column, 'options.select_load_ajax'))) {
            return false;
        }

        // get count table..
        if (boolval($callQuery)) {
            $count = $this->getOptionsQuery($options)->count();
            // when count > 0, create option only value.
            return $count <= config('exment.select_table_limit_count', 100);
        }

        return true;
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
    public function getSelectOptions($options = [])
    {
        extract(array_merge(
            [
                'selected_value' => null,
                'display_table' => null,
                'all' => false,
                'showMessage_ifDeny' => null,
                'filterCallback' => null,
                'target_view' => null,
                'permission' => null,
                'notAjax' => false,
                'custom_column' => null,
            ],
            $options
        ));

        // if ajax, return []. (set callQuery is false)
        if (!$this->isGetOptions(array_merge(['callQuery' => false], $options))) {
            return [];
        }

        // get query
        $query = $this->getOptionsQuery($options);

        // when count > 100, create option only value.
        if (!$this->isGetOptions($options)) {
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
    public function getOptionAjaxUrl($options = [])
    {
        // if use options, return null
        if ($this->isGetOptions($options)) {
            return null;
        }
        return admin_urls("webapi", 'data', array_get($this, 'table_name'), "select");
    }

    /**
     * getOptionsQuery. this function uses for count, get, ...
     */
    protected function getOptionsQuery($options = [])
    {
        extract(array_merge(
            [
                'selected_value' => null,
                'display_table' => null,
                'all' => false,
                'showMessage_ifDeny' => null,
                'filterCallback' => null,
                'target_view' => null,
                'permission' => null,
                'notAjax' => false,
                'custom_column' => null,
            ],
            $options
        ));

        if (is_null($display_table)) {
            $display_table = $this;
        } else {
            $display_table = self::getEloquent($display_table);
        }
        $table_name = $this->table_name;
        // get query.
        // if org
        if (in_array($table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION]) && in_array($display_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
            $query = $this->getValueModel();
        }
        // if $table_name is user or organization, get from getRoleUserOrOrg
        elseif ($table_name == SystemTableName::USER && !$all) {
            $query = AuthUserOrgHelper::getRoleUserQueryTable($display_table, $permission);
        } elseif ($table_name == SystemTableName::ORGANIZATION && !$all) {
            $query = AuthUserOrgHelper::getRoleOrganizationQuery($display_table, $permission);
        } else {
            $query = $this->getValueModel();
        }

        // filter model using view
        if (isset($target_view)) {
            $user = Admin::user();
            if (isset($user)) {
                $query = $user->filterModel($query, $target_view);
            }
        }

        if (isset($filterCallback)) {
            $query = $filterCallback($query);
        }

        return $query;
    }

    /**
     * get columns select options.
     * 'append_table': whether appending custom table id in options value
     * 'index_enabled_only': only getting index column
     * 'include_parent': whether getting parent table's column
     * 'include_child': whether getting child table's column
     * 'include_select_table': whether getting select table's column
     * 'include_system': whether getting system column
     * @param array $selectOptions
     * @param option items
     */
    //public function getColumnsSelectOptions($append_table = false, $index_enabled_only = false, $include_parent = false, $include_child = false, $include_system = true)
    public function getColumnsSelectOptions($selectOptions = [])
    {
        $selectOptions = array_merge(
            [
                'append_table' => false,
                'index_enabled_only' => false,
                'include_parent' => false,
                'include_child' => false,
                'include_select_table' => false,
                'include_system' => true,
            ],
            $selectOptions
        );
        extract($selectOptions);

        $options = [];
        
        // getting this table's column options
        $this->setColumnOptions(
            $options,
            $this->custom_columns,
            $this->id,
            [
                'append_table' => $append_table,
                'index_enabled_only' => $index_enabled_only,
                'include_parent' => true,
                'include_system' => $include_system,
            ]
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
                    $parent_id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => true,
                        'include_system' => $include_system,
                        'table_view_name' => $tablename,
                        'view_pivot_column' => SystemColumn::PARENT_ID,
                        'view_pivot_table' => $this,
                    ]
                );
            }
            ///// get select table columns
            $select_table_columns = $this->getSelectTableColumns();
            foreach ($select_table_columns as $select_table_column) {
                $select_table = $select_table_column->column_item->getSelectTable();
                $tablename = array_get($select_table, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $select_table->custom_columns,
                    $select_table->id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => true,
                        'include_system' => $include_system,
                        'table_view_name' => $tablename,
                        'view_pivot_column' => $select_table_column,
                        'view_pivot_table' => $this,
                    ]
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
                    $child_id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => false,
                        'include_system' => true,
                        'table_view_name' => $tablename,
                    ]
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
                    $custom_table->id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => true,
                        'include_system' => true,
                        'table_view_name' => $tablename,
                    ]
                );
            }
        }
    
        return $options;
    }

    protected function setColumnOptions(&$options, $custom_columns, $table_id, $selectOptions = [])
    {
        $selectOptions = array_merge(
            [
                'append_table' => false,
                'index_enabled_only' => false,
                'include_parent' => false,
                'include_system' => true,
                'table_view_name' => null,
                'view_pivot_column' => null,
                'view_pivot_table' => null,
            ],
            $selectOptions
        );
        extract($selectOptions);

        // get option key
        $optionKeyParams = [
            'view_pivot_column' => $view_pivot_column,
            'view_pivot_table' => $view_pivot_table,
        ];

        if ($include_system) {
            /// get system columns
            foreach (SystemColumn::getOptions(['header' => true]) as $option) {
                $key = static::getOptionKey(array_get($option, 'name'), $append_table, $table_id);
                $value = exmtrans('common.'.array_get($option, 'name'));
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
        }

        if ($include_parent) {
            $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $table_id)->first();
            ///// if this table is child relation(1:n), add parent table
            if (isset($relation)) {
                $key = static::getOptionKey('parent_id', $append_table, $table_id);
                $value = array_get($relation, 'parent_custom_table.table_view_name');
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
        }

        foreach ($custom_columns as $custom_column) {
            // if $index_enabled_only = true and options.index_enabled_only is false, continue
            if ($index_enabled_only && !$custom_column->index_enabled) {
                continue;
            }
            $key = static::getOptionKey(array_get($custom_column, 'id'), $append_table, $table_id, $optionKeyParams);
            $value = array_get($custom_column, 'column_view_name');
            static::setKeyValueOption($options, $key, $value, $table_view_name);
        }

        if ($include_system) {
            ///// get system columns
            foreach (SystemColumn::getOptions(['footer' => true]) as $option) {
                $key = static::getOptionKey(array_get($option, 'name'), $append_table, $table_id, $optionKeyParams);
                $value = exmtrans('common.'.array_get($option, 'name'));
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
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
            $key = static::getOptionKey(array_get($option, 'name'));
            $options[$key] = exmtrans('common.'.array_get($option, 'name'));
        }

        ///// get table columns
        $custom_columns = $this->custom_columns;
        foreach ($custom_columns as $option) {
            $column_type = array_get($option, 'column_type');
            if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                $key = static::getOptionKey(array_get($option, 'id'));
                $options[$key] = array_get($option, 'column_view_name');
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
                $key = static::getOptionKey(array_get($option, 'name'), true, $tableid);
                $options[$key] = $tablename . ' : ' . exmtrans('common.'.array_get($option, 'name'));
            }
            $child_columns = $child->custom_columns;
            foreach ($child_columns as $option) {
                $column_type = array_get($option, 'column_type');
                if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                    $key = static::getOptionKey(array_get($option, 'id'), true, $tableid);
                    $options[$key] = $tablename . ' : ' . array_get($option, 'column_view_name');
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
                $key = static::getOptionKey(array_get($option, 'name'), true, $custom_table->id);
                $options[$key] = $tablename . ' : ' . exmtrans('common.'.array_get($option, 'name'));
            }
            foreach ($custom_table->custom_columns as $option) {
                $column_type = array_get($option, 'column_type');
                if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                    $key = static::getOptionKey(array_get($option, 'id'), true, $custom_table->id);
                    $options[$key] = $tablename . ' : ' . array_get($option, 'column_view_name');
                }
            }
        }
    
        return $options;
    }

    /**
     * get date columns select options. It contains date, datetime.
     *
     */
    public function getDateColumnsSelectOptions()
    {
        $options = [];

        ///// get table columns
        $custom_columns = $this->custom_columns;
        foreach ($custom_columns as $option) {
            if (!$option->index_enabled) {
                continue;
            }
            $column_type = array_get($option, 'column_type');
            if (ColumnType::isDate($column_type)) {
                $options[static::getOptionKey(array_get($option, 'id'))] = array_get($option, 'column_view_name');
            }
        }
        
        /// get system date columns
        foreach (SystemColumn::getOptions(['type' => 'datetime']) as $option) {
            $options[static::getOptionKey(array_get($option, 'name'))] = exmtrans('common.'.array_get($option, 'name'));
        }

        return $options;
    }
        
    /**
     * Get relation tables list.
     * It contains search_type(select_table, one_to_many, many_to_many)
     */
    public function getRelationTables()
    {
        // check already execute
        $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_RELATION_TABLES, $this->table_name);
        return System::requestSession($key, function () {
            $results = [];
            // 1. Get tables as "select_table". They contains these columns matching them.
            // * table_column > options > search_enabled is true.
            // * table_column > options > select_target_table is table id user selected.
            $tables = static::whereHas('custom_columns', function ($query) {
                $query
                ->withoutGlobalScope(OrderScope::class)
                ->whereIn('options->index_enabled', [1, "1"])
                ->whereIn('options->select_target_table', [$this->id, strval($this->id)]);
            })
            ->searchEnabled()
            ->get();
    
            foreach ($tables as $table) {
                // if not role, continue
                $table_obj = static::getEloquent(array_get($table, 'id'));
                if (!$table_obj->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                    continue;
                }
                array_push($results, ['searchType' => SearchType::SELECT_TABLE, 'table' => $table_obj]);
            }
    
            // 2. Get relation tables.
            // * table "custom_relations" and column "parent_custom_table_id" is $this->id.
            $tables = static
            ::join('custom_relations', 'custom_tables.id', 'custom_relations.parent_custom_table_id')
            ->join('custom_tables AS child_custom_tables', 'child_custom_tables.id', 'custom_relations.child_custom_table_id')
                ->whereHas('custom_relations', function ($query) {
                    $query->where('parent_custom_table_id', $this->id);
                })->get(['child_custom_tables.*', 'custom_relations.relation_type'])->toArray();
            foreach ($tables as $table) {
                // if not role, continue
                $table_obj = static::getEloquent(array_get($table, 'id'));
                if (!$table_obj->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                    continue;
                }
                $searchType = array_get($table, 'relation_type') == RelationType::ONE_TO_MANY ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY;
                array_push($results, ['searchType' => $searchType, 'table' => $table_obj]);
            }
    
            return $results;
        });
    }

    public function getValueModel($id = null, $withTrashed = false)
    {
        $modelname = getModelName($this);
        if (isset($id)) {
            $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $this->table_name, $id);
            $model = System::requestSession($key, function () use ($id, $withTrashed) {
                if ($withTrashed) {
                    return getModelName($this->table_name)::withTrashed()->find($id);
                }
                return getModelName($this->table_name)::find($id);
            });
        } else {
            $model = new $modelname;
        }
        
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
     * Whether login user has permission about view.
     */
    public function hasViewPermission()
    {
        return !boolval(config('exment.userview_disabled', false)) ||
            $this->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VIEW]);
    }
    
    /**
     * Whether login user has permission about target id data.
     */
    public function hasPermissionData($id)
    {
        return $this->_hasPermissionData($id, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE, Permission::AVAILABLE_ALL_CUSTOM_VALUE, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE);
    }

    /**
     * Whether login user has edit permission about target id data.
     */
    public function hasPermissionEditData($id)
    {
        return $this->_hasPermissionData($id, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE, Permission::AVAILABLE_ALL_EDIT_CUSTOM_VALUE, Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
    }

    /**
     * Whether login user has permission about target id data. (protected function)
     *
     * @$tableRole if user doesn't have these permission, return false
     * @$tableRoleTrue if user has these permission, return true
     */
    protected function _hasPermissionData($id, $tableRole, $tableRoleTrue, $dataRole)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        // if user doesn't have all permissons about target table, return false.
        if (!$this->hasPermission($tableRole)) {
            return false;
        }

        // if user has all edit table, return true.
        if ($this->hasPermission($tableRoleTrue)) {
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
        if ($this->checkPermissionWithPivot($rows, $dataRole)) {
            return true;
        }

        // else, get model using value_authoritable. (only that system uses organization.)
        // if count > 0, return true.
        if (System::organization_available()) {
            $rows = $model->getAuthoritable(SystemTableName::ORGANIZATION);
            if ($this->checkPermissionWithPivot($rows, $dataRole)) {
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

        if (is_string($role_key)) {
            $role_key = [$role_key];
        }

        foreach ($rows as $row) {
            // check role permissions
            $authoritable_type = array_get($row, 'pivot.authoritable_type');
            if (in_array($authoritable_type, $role_key)) {
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
        return !System::permission_available()
            || boolval($this->getOption('all_user_editable_flg'))
            || boolval($this->getOption('all_user_viewable_flg'))
            || boolval($this->getOption('all_user_accessable_flg'));
    }
}
