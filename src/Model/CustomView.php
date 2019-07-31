<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Builder;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewColumnSort;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\SystemColumn;

class CustomView extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DefaultFlgTrait;
    use Traits\TemplateTrait;
    use Traits\DatabaseJsonTrait;

    protected $appends = ['view_calendar_target', 'pager_count'];
    protected $guarded = ['id', 'suuid'];
    protected $casts = ['options' => 'json'];
    protected $with = ['custom_table', 'custom_view_columns'];

    public static $templateItems = [
        'excepts' => ['custom_table', 'target_view_name', 'view_calendar_target', 'pager_count'],
        'uniqueKeys' => ['suuid'],
        'langs' => [
            'keys' => ['suuid'],
            'values' => ['view_view_name'],
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'custom_table_id',
                        'replacedName' => [
                            'table_name' => 'table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
        'defaults' => [
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ],
        'enums' => [
            'view_type' => ViewType::class,
            'view_kind_type' => ViewKindType::class,
        ],
        'children' =>[
            'custom_view_columns' => CustomViewColumn::class,
            'custom_view_filters' => CustomViewFilter::class,
            'custom_view_sorts' => CustomViewSort::class,
            'custom_view_summaries' => CustomViewSummary::class,
        ],
    ];


    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_view_columns()
    {
        return $this->hasMany(CustomViewColumn::class, 'custom_view_id');
    }

    public function custom_view_filters()
    {
        return $this->hasMany(CustomViewFilter::class, 'custom_view_id');
    }

    public function custom_view_sorts()
    {
        return $this->hasMany(CustomViewSort::class, 'custom_view_id');
    }

    public function custom_view_summaries()
    {
        return $this->hasMany(CustomViewSummary::class, 'custom_view_id');
    }

    public function getTableNameAttribute()
    {
        return $this->custom_table->table_name;
    }

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }

    public function deletingChildren()
    {
        $this->custom_view_columns()->delete();
        $this->custom_view_filters()->delete();
        $this->custom_view_sorts()->delete();
        $this->custom_view_summaries()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($model) {
            $model->prepareJson('options');
        });

        static::creating(function ($model) {
            $model->setDefaultFlgInTable('setDefaultFlgFilter', 'setDefaultFlgSet');
        });
        static::updating(function ($model) {
            $model->setDefaultFlgInTable('setDefaultFlgFilter', 'setDefaultFlgSet');
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
        });
        
        // add global scope
        static::addGlobalScope('showableViews', function (Builder $builder) {
            return static::showableViews($builder);
        });
    }

    protected function setDefaultFlgFilter($query)
    {
        $query->where('view_type', $this->view_type);

        if ($this->view_type == ViewType::USER) {
            $query->where('created_user_id', \Exment::user()->base_user_id);
        }
    }

    protected function setDefaultFlgSet()
    {
        // set if only this flg is system
        if ($this->view_type == ViewType::SYSTEM) {
            $this->default_flg = true;
        }
    }

    // custom function --------------------------------------------------
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * set laravel-admin grid using custom_view
     */
    public function setGrid($grid)
    {
        $custom_table = $this->custom_table;
        // get view columns
        $custom_view_columns = $this->custom_view_columns;
        foreach ($custom_view_columns as $custom_view_column) {
            $item = $custom_view_column->column_item
                ->label(array_get($custom_view_column, 'view_column_name'));
            $grid->column($item->indexEnabled() ? $item->index() : $item->name(), $item->label())
                ->sort($item->sortable())
                ->cast($item->getCastName())
                ->display(function ($v) use ($item) {
                    if (is_null($this)) {
                        return '';
                    }
                    return $item->setCustomValue($this)->html();
                });
        }

        // set parpage
        if (is_null(request()->get('per_page')) && isset($this->pager_count) && $this->pager_count > 0) {
            $grid->paginate($this->pager_count);
        }
    }
    
    /**
     * set DataTable using custom_view
     * @return list(array, array) headers, bodies
     */
    public function getDataTable($datalist, $options = [])
    {
        $options = array_merge(
            [
                'action_callback' => null,
            ],
            $options
        );
        $custom_table = $this->custom_table;
        // get custom view columns and custom view summaries
        $view_column_items = $this->getSummaryIndexAndViewColumns();
        
        // create headers
        $headers = [];
        foreach ($view_column_items as $view_column_item) {
            $item = array_get($view_column_item, 'item');
            $headers[] = $item
                ->column_item
                ->label(array_get($item, 'view_column_name'))
                ->label();
        }
        if ($this->view_kind_type != ViewKindType::AGGREGATE) {
            $headers[] = trans('admin.action');
        }
        
        // get table bodies
        $bodies = [];
        if (isset($datalist)) {
            foreach ($datalist as $data) {
                $body_items = [];
                foreach ($view_column_items as $view_column_item) {
                    $column = array_get($view_column_item, 'item');
                    $item = $column->column_item;
                    if ($this->view_kind_type == ViewKindType::AGGREGATE) {
                        $index = array_get($view_column_item, 'index');
                        $summary_condition = array_get($column, 'view_summary_condition');
                        $item->options([
                            'summary' => true,
                            'summary_index' => $index,
                            'summary_condition' => $summary_condition,
                            'group_condition' => array_get($column, 'view_group_condition'),
                            'disable_currency_symbol' => ($summary_condition == SummaryCondition::COUNT),
                        ]);
                    }
                    $body_items[] = $item->setCustomValue($data)->html();
                }

                $link = '';
                if (isset($options['action_callback'])) {
                    $options['action_callback']($link, $custom_table, $data);
                }

                ///// add show and edit link
                if ($this->view_kind_type != ViewKindType::AGGREGATE) {
                    // using role
                    $link .= (new Linker)
                        ->url(admin_urls('data', array_get($custom_table, 'table_name'), array_get($data, 'id')))
                        //->linkattributes(['style' => "margin:0 3px;"])
                        ->icon('fa-eye')
                        ->tooltip(trans('admin.show'))
                        ->render();
                    if ($custom_table->hasPermissionEditData(array_get($data, 'id'))) {
                        $link .= (new Linker)
                            ->url(admin_urls('data', array_get($custom_table, 'table_name'), array_get($data, 'id'), 'edit'))
                            ->icon('fa-edit')
                            ->tooltip(trans('admin.edit'))
                            ->render();
                    }
                    // add hidden item about data id
                    $link .= '<input type="hidden" data-id="'.array_get($data, 'id').'" />';
                    $body_items[] = $link;
                }

                // add items to body
                $bodies[] = $body_items;
            }
        }

        //return headers, bodies
        return [$headers, $bodies];
    }

    /**
     * get alldata view using table
     *
     * @param mixed $tableObj table_name, object or id eic
     * @return void
     */
    public static function getAllData($tableObj)
    {
        // get all data view
        $view = $tableObj->custom_views()->where('view_kind_type', ViewKindType::ALLDATA)->first();

        // if all data view is not exists, create view
        if (!isset($view)) {
            $view = static::createDefaultView($tableObj);
        }

        // if target form doesn't have columns, add columns for has_index_columns columns.
        if (is_null($view->custom_view_columns) || count($view->custom_view_columns) == 0) {
            // get view id for after
            $view->createDefaultViewColumns();

            // re-get view (reload view_columns)
            $view = static::find($view->id);
        }

        return $view;
    }

    /**
     * get default view using table
     *
     * @param mixed $tableObj table_name, object or id eic
     * @param boolean $getSettingValue if true, getting from UserSetting table
     * @return void
     */
    public static function getDefault($tableObj, $getSettingValue = true)
    {
        $user = Admin::user();
        $tableObj = CustomTable::getEloquent($tableObj);

        // get request
        $request = request();

        // get view using query
        if (!is_null($request->input('view'))) {
            $suuid = $request->input('view');
            // if query has view id, set view.
            $view = static::findBySuuid($suuid);

            // set user_setting
            if (!is_null($user)) {
                $user->setSettingValue(implode(".", [UserSetting::VIEW, $tableObj->table_name]), $suuid);
            }
        }
        // if url doesn't contain view query, get view user setting.
        if (!isset($view) && !is_null($user) && $getSettingValue) {
            // get suuid
            $suuid = $user->getSettingValue(implode(".", [UserSetting::VIEW, $tableObj->table_name]));
            $view = CustomView::findBySuuid($suuid);
        }
        // if url doesn't contain view query, get custom view. first
        if (!isset($view)) {
            $view = static::allRecords(function ($record) use ($tableObj) {
                return array_get($record, 'custom_table_id') == $tableObj->id
                    && array_get($record, 'default_flg') == true 
                    && array_get($record, 'view_kind_type') != ViewKindType::FILTER;
            })->first();
            // $view = $tableObj->custom_views()->where('default_flg', true)
            //     ->where('view_kind_type', '<>', ViewKindType::FILTER)->first();
        }
        
        // if default view is not setting, show all data view
        if (!isset($view)) {
            // get all data view
            $alldata = static::allRecords(function ($record) use ($tableObj) {
                return array_get($record, 'custom_table_id') == $tableObj->id
                    && array_get($record, 'view_kind_type') == ViewKindType::ALLDATA;
            })->first();
            //$tableObj->custom_views()->where('view_kind_type', ViewKindType::ALLDATA)->first();
            // if all data view is not exists, create view and column
            if (!isset($alldata)) {
                $alldata = static::createDefaultView($tableObj);
                $alldata->createDefaultViewColumns();
            }
            $view = $alldata;
        }

        // if target form doesn't have columns, add columns for has_index_columns columns.
        if (is_null($view->custom_view_columns) || count($view->custom_view_columns) == 0) {
            // get view id for after
            $view->createDefaultViewColumns();

            // re-get view (reload view_columns)
            $view = static::find($view->id);
        }

        return $view;
    }
    
    protected static function showableViews($query)
    {
        return $query->where(function ($query) {
            $query->where(function ($query) {
                $query->where('view_type', ViewType::SYSTEM);
            })->orWhere(function ($query) {
                $query->where('view_type', ViewType::USER)
                        ->where('created_user_id', \Exment::user()->base_user_id ?? null);
            });
        });
    }

    public static function createDefaultView($tableObj)
    {
        $tableObj = CustomTable::getEloquent($tableObj);
        
        $view = new CustomView;
        $view->custom_table_id = $tableObj->id;
        $view->view_type = ViewType::SYSTEM;
        $view->view_kind_type = ViewKindType::ALLDATA;
        $view->view_view_name = exmtrans('custom_view.alldata_view_name');
        $view->saveOrFail();
        
        return $view;
    }

    public function createDefaultViewColumns()
    {
        $view_columns = [];
        // set default view_column
        foreach (SystemColumn::getOptions(['default' => true]) as $view_column_system) {
            $view_column = new CustomViewColumn;
            $view_column->custom_view_id = $this->id;
            $view_column->view_column_target = array_get($view_column_system, 'name');
            $view_column->order = array_get($view_column_system, 'order');
            array_push($view_columns, $view_column);
        }
        $this->custom_view_columns()->saveMany($view_columns);
        return $view_columns;
    }

    /**
     * set value filters
     */
    public function setValueFilters($model, $db_table_name = null)
    {
        foreach ($this->custom_view_filters as $filter) {
            $model = $filter->setValueFilter($model, $db_table_name);
        }
        return $model;
    }

    /**
     * set value sort
     */
    public function setValueSort($model)
    {
        // if request has "_sort", not executing
        if (request()->has('_sort')) {
            return $model;
        }
        foreach ($this->custom_view_sorts as $custom_view_sort) {
            // get column target column
            if ($custom_view_sort->view_column_type == ViewColumnType::COLUMN) {
                $view_column_target = $custom_view_sort->custom_column->column_item->getSortColumn();
                $sort_order = $custom_view_sort->sort == ViewColumnSort::ASC ? 'asc' : 'desc';
                //set order
                $model->orderByRaw("$view_column_target $sort_order");
            } else {
                if ($custom_view_sort->view_column_type == ViewColumnType::SYSTEM) {
                    $system_info = SystemColumn::getOption(['id' => array_get($custom_view_sort, 'view_column_target_id')]);
                    $view_column_target = array_get($system_info, 'sqlname') ?? array_get($system_info, 'name');
                } elseif ($custom_view_sort->view_column_type == ViewColumnType::PARENT_ID) {
                    $view_column_target = 'parent_id';
                }
                //set order
                $model->orderby($view_column_target, $custom_view_sort->sort == ViewColumnSort::ASC ? 'asc' : 'desc');
            }
        }

        return $model;
    }

    /**
     * set value summary
     */
    public function getValueSummary(&$query, $table_name, $grid = null)
    {
        // get table id
        $db_table_name = getDBTableName($table_name);

        $group_columns = [];
        $sort_columns = [];
        $custom_tables = [];

        // get relation parent tables
        $parent_relations = CustomRelation::getRelationsByChild($this->custom_table);
        // get relation child tables
        $child_relations = CustomRelation::getRelationsByParent($this->custom_table);
        // join select table refered from this table.
        $select_table_columns = $this->custom_table->getSelectTables();
        // join table refer to this table as select.
        $selected_table_columns = $this->custom_table->getSelectedTables();
        
        // set grouping columns
        $view_column_items = $this->getSummaryIndexAndViewColumns();
        foreach ($view_column_items as $view_column_item) {
            $item = array_get($view_column_item, 'item');
            $index = array_get($view_column_item, 'index');
            $column_item = $item->column_item;
            // set order column
            if (!empty(array_get($item, 'sort_order'))) {
                $sort_order = array_get($item, 'sort_order');
                $sort_type = array_get($item, 'sort_type');
                $sort_columns[] = ['key' => $sort_order, 'sort_type' => $sort_type, 'column_name' => "column_$index"];
            }

            if ($item instanceof CustomViewColumn) {
                // check child item
                $is_child = $child_relations->contains(function ($value, $key) use ($item) {
                    return isset($item->custom_table) && $value->child_custom_table->id == $item->custom_table->id;
                });

                // first, set group_column. this column's name uses index.
                $column_item->options(['groupby' => true, 'group_condition' => array_get($item, 'view_group_condition'), 'summary_index' => $index, 'is_child' => $is_child]);
                $groupSqlName = $column_item->sqlname();
                $groupSqlAsName = $column_item->sqlAsName();
                $group_columns[] = $groupSqlName;
                $column_item->options(['groupby' => false, 'group_condition' => null]);

                // parent_id need parent_type
                if ($column_item instanceof \Exceedone\Exment\ColumnItems\ParentItem) {
                    $group_columns[] = $column_item->sqltypename();
                }

                $this->setSummaryItem($column_item, $index, $custom_tables, $grid, [
                    'column_label' => array_get($item, 'view_column_name')?? $column_item->label(),
                    'custom_view_column' => $item,
                ]);
                
                // if this is child table, set as sub group by
                if ($is_child) {
                    $custom_tables[$item->custom_table->id]['subGroupby'][] = $groupSqlAsName;
                    $custom_tables[$item->custom_table->id]['select_group'][] = $groupSqlName;
                }
            }
            // set summary columns
            else {
                $this->setSummaryItem($column_item, $index, $custom_tables, $grid, [
                    'column_label' => array_get($item, 'view_column_name')?? $column_item->label(),
                    'summary_condition' => $item->view_summary_condition
                ]);
            }
        }

        // set filter columns
        foreach ($this->custom_view_filters as $custom_view_filter) {
            $target_table_id = array_get($custom_view_filter, 'view_column_table_id');

            if (array_key_exists($target_table_id, $custom_tables)) {
                $custom_tables[$target_table_id]['filter'][] = $custom_view_filter;
            } else {
                $custom_tables[$target_table_id] = [
                    'table_name' => getDBTableName($target_table_id),
                    'filter' => [$custom_view_filter]
                ];
            }
        }

        $sub_queries = [];

        $custom_table_id = $this->custom_table->id;

        foreach ($custom_tables as $table_id => $custom_table) {
            // add select column and filter
            if ($table_id == $custom_table_id) {
                $this->addQuery($query, $db_table_name, $custom_table);
                continue;
            }
            // join parent table
            if ($parent_relations->contains(function ($value, $key) use ($table_id) {
                return $value->parent_custom_table->id == $table_id;
            })) {
                $this->addQuery($query, $db_table_name, $custom_table, 'parent_id', 'id');
                continue;
            }
            // create subquery grouping child table
            if ($child_relations->contains(function ($value, $key) use ($table_id) {
                return $value->child_custom_table->id == $table_id;
            })) {
                $sub_query = $this->getSubQuery($db_table_name, 'id', 'parent_id', $custom_table);
                if (array_key_exists('select_group', $custom_table)) {
                    $query = $query->addSelect($custom_table['select_group']);
                }
                $sub_queries[] = $sub_query;
                continue;
            }
            // join table refered from target table
            if (in_array($table_id, $select_table_columns)) {
                $column_key = array_search($table_id, $select_table_columns);
                $this->addQuery($query, $db_table_name, $custom_table, $column_key, 'id');
                continue;
            }
            // create subquery grouping table refer to target table
            if (in_array($table_id, $selected_table_columns)) {
                $column_key = array_search($table_id, $selected_table_columns);
                $sub_query = $this->getSubQuery($db_table_name, 'id', $column_key, $custom_table);
                if (array_key_exists('select_group', $custom_table)) {
                    $query = $query->addSelect($custom_table['select_group']);
                }
                $sub_queries[] = $sub_query;
                continue;
            }
        }

        // join subquery
        foreach ($sub_queries as $table_no => $sub_query) {
            $query = $query->leftjoin(\DB::raw('('.$sub_query->toSql().") As table_$table_no"), $db_table_name.'.id', "table_$table_no.id");
            $query = $query->mergeBindings($sub_query);
        }

        if (count($sort_columns) > 0) {
            $orders = collect($sort_columns)->sortBy('key')->all();
            foreach ($orders as $order) {
                $sort = ViewColumnSort::getEnum(array_get($order, 'sort_type'), ViewColumnSort::ASC)->lowerKey();
                $query = $query->orderBy(array_get($order, 'column_name'), $sort);
            }
        }
        // set sql grouping columns
        $query = $query->groupBy($group_columns);

        return $query;
    }
    
    /**
     * set summary item
     */
    protected function setSummaryItem($item, $index, &$custom_tables, $grid, $options = [])
    {
        extract(array_merge(
            [
                'column_label' => null,
                'summary_condition' => null,
                'custom_view_column' => null,
            ],
            $options
        ));

        $item->options([
            'summary' => true,
            'summary_condition' => $summary_condition,
            'summary_index' => $index,
            'disable_currency_symbol' => ($summary_condition == SummaryCondition::COUNT),
            'group_condition' => array_get($custom_view_column, 'view_group_condition'),
        ]);

        $table_id = $item->getCustomTable()->id;
        $db_table_name = getDBTableName($table_id);

        // set sql parts for custom table
        if (!array_key_exists($table_id, $custom_tables)) {
            $custom_tables[$table_id] = [ 'table_name' => $db_table_name ];
        }

        $custom_tables[$table_id]['select'][] = $item->sqlname();
        if ($item instanceof \Exceedone\Exment\ColumnItems\ParentItem) {
            $custom_tables[$table_id]['select'][] = $item->sqltypename();
        }

        if (isset($summary_condition)) {
            $custom_tables[$table_id]['select_group'][] = $item->getGroupName();
        }
        
        if (isset($grid)) {
            $grid->column("column_".$index, $column_label)
            ->sort($item->sortable())
            ->display(function ($id) use ($item, $index) {
                $option = SystemColumn::getOption(['name' => $item->name()]);
                if (array_get($option, 'type') == 'user') {
                    return esc_html(getUserName($id));
                } else {
                    return $item->setCustomValue($this)->html();
                }
            });
        }
    }

    /**
     * add select column and filter and join table to main query
     */
    protected function addQuery(&$query, $table_main, $custom_table, $key_main = null, $key_sub = null)
    {
        $table_name = array_get($custom_table, 'table_name');
        if ($table_name != $table_main) {
            $query = $query->join($table_name, "$table_main.$key_main", "$table_name.$key_sub");
            $query = $query->whereNull("$table_name.deleted_at");
        }
        if (array_key_exists('select', $custom_table)) {
            $query = $query->addSelect($custom_table['select']);
        }
        if (array_key_exists('filter', $custom_table)) {
            foreach ($custom_table['filter'] as $filter) {
                $filter->setValueFilter($query, $table_name);
            }
        }
    }
    
    /**
     * add select column and filter and join table to sub query
     */
    protected function getSubQuery($table_main, $key_main, $key_sub, $custom_table)
    {
        $table_name = array_get($custom_table, 'table_name');
        // get subquery groupbys
        $groupBy = array_get($custom_table, 'subGroupby', []);
        $groupBy[] = "$table_name.$key_sub";

        $sub_query = \DB::table($table_main)
            ->select("$table_name.$key_sub as id")
            ->join($table_name, "$table_main.$key_main", "$table_name.$key_sub")
            ->whereNull("$table_name.deleted_at")
            ->groupBy($groupBy);
        if (array_key_exists('select', $custom_table)) {
            $sub_query->addSelect($custom_table['select']);
        }
        if (array_key_exists('filter', $custom_table)) {
            foreach ($custom_table['filter'] as $filter) {
                $filter->setValueFilter($sub_query, $table_name);
            }
        }
        return $sub_query;
    }

    /**
     * Get arrays about Summary Column and custom_view_columns and custom_view_summaries
     *
     * @return void
     */
    public function getSummaryIndexAndViewColumns()
    {
        $results = [];
        // set grouping columns
        foreach ($this->custom_view_columns as $custom_view_column) {
            $results[] = [
                'index' => ViewKindType::DEFAULT . '_' . $custom_view_column->id,
                'item' => $custom_view_column,
            ];
        }
        // set summary columns
        foreach ($this->custom_view_summaries as $custom_view_summary) {
            $results[] = [
                'index' => ViewKindType::AGGREGATE . '_' . $custom_view_summary->id,
                'item' => $custom_view_summary,
            ];
            $item = $custom_view_summary->column_item;
        }

        return $results;
    }

    /**
     * get columns select options. It contains system column(ex. id, suuid, created_at, updated_at), and table columns.
     * @param $is_number
     */
    public function getColumnsSelectOptions($is_number = null)
    {
        $options = [];
        
        foreach ($this->custom_view_columns as $custom_view_column) {
            $option = $this->getSelectColumn(ViewKindType::DEFAULT, $custom_view_column);
            if (is_null($is_number) || array_get($option, 'is_number') == $is_number) {
                $options[] = $option;
            }
        }

        foreach ($this->custom_view_summaries as $custom_view_summary) {
            $option = $this->getSelectColumn(ViewKindType::AGGREGATE, $custom_view_summary);
            if (is_null($is_number) || array_get($option, 'is_number') == $is_number) {
                $options[] = $option;
            }
        }

        return $options;
    }

    protected function getSelectColumn($column_type, $custom_view_column)
    {
        $view_column_type = array_get($custom_view_column, 'view_column_type');
        $view_column_id = $column_type . '_' . array_get($custom_view_column, 'id');

        $custom_table_id = $this->custom_table_id;
        $column_view_name = array_get($custom_view_column, 'view_column_name');
        $is_number = false;

        switch ($view_column_type) {
            case ViewColumnType::COLUMN:
                $column = $custom_view_column->custom_column;
                $is_number = ColumnType::isCalc(array_get($column, 'column_type'));

                if (is_nullorempty($column_view_name)) {
                    $column_view_name = array_get($column, 'column_view_name');
                    // if table is not equal target table, add table name to column name.
                    if ($custom_table_id != array_get($column, 'custom_table_id')) {
                        $column_view_name = array_get($column->custom_table, 'table_view_name') . '::' . $column_view_name;
                    }
                }
                break;
            case ViewColumnType::SYSTEM:
                $system_info = SystemColumn::getOption(['id' => array_get($custom_view_column, 'view_column_target_id')]);
                if (is_nullorempty($column_view_name)) {
                    $column_view_name = exmtrans('common.'.$system_info['name']);
                }
                break;
            case ViewColumnType::PARENT_ID:
                $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->custom_table_id)->first();
                ///// if this table is child relation(1:n), add parent table
                if (isset($relation)) {
                    $column_view_name = array_get($relation, 'parent_custom_table.table_view_name');
                }
                break;
        }

        if (array_get($custom_view_column, 'view_summary_condition') == SummaryCondition::COUNT) {
            $is_number = true;
        }
        return ['id' => $view_column_id, 'text' => $column_view_name, 'is_number' => $is_number];
    }

    public function getViewCalendarTargetAttribute()
    {
        $custom_view_columns = $this->custom_view_columns;
        if (count($custom_view_columns) > 0) {
            return $custom_view_columns[0]->view_column_target;
        }
        return null;
    }

    public function setViewCalendarTargetAttribute($view_calendar_target)
    {
        $custom_view_columns = $this->custom_view_columns;
        if (count($custom_view_columns) == 0) {
            $this->custom_view_columns[] = new CustomViewColumn();
        }
        $custom_view_columns[0]->view_column_target = $view_calendar_target;
    }
    
    public function getPagerCountAttribute()
    {
        return $this->getOption('pager_count');
    }

    public function setPagerCountAttribute($val)
    {
        $this->setOption('pager_count', $val);

        return $this;
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return boolval($this->view_kind_type == ViewKindType::ALLDATA);
    }
    
    /**
     * get all records. use system session
     */
    public static function allRecords(\Closure $filter = null, $isGetAll = true)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName());
        // get from request session
        $records = System::requestSession($key, function () {
            return self::all();
        });

        // execute filter
        if (isset($filter)) {
            $records = $records->filter(function ($record) use ($filter) {
                return $filter($record);
            });
        }

        // if exists, return
        if (count($records) > 0) {
            return $records;
        }
        
        if ((!isset($records) || count($records) == 0) && !$isGetAll) {
            return $records;
        }

        // else, get all again
        $records = self::all();
        System::requestSession($key, $records);

        if (!isset($records)) {
            return $records;
        }

        // execute filter
        if (isset($filter)) {
            $records = $records->filter(function ($record) use ($filter) {
                return $filter($record);
            });
        }
        return $records;
    }
}
