<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Linker;
use Illuminate\Http\Request as Req;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
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
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = ['id', 'suuid'];

    protected static $uniqueKeyName = ['suuid'];

    protected static $templateItems = [
        'excepts' => ['id', 'custom_table', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'deleted_user_id'],
        'keys' => ['suuid'],
        'langs' => ['view_view_name'],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'custom_table_id',
                        'replacedName' => [
                            'table_name' => 'custom_table.table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
        'children' =>[
            'custom_view_columns',
            'custom_view_filters',
            'custom_view_sorts',
            'custom_view_summaries',
        ],
    ];


    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_view_columns()
    {
        return $this->hasMany(CustomViewColumn::class, 'custom_view_id')->orderBy('order');
    }

    public function custom_view_filters()
    {
        return $this->hasMany(CustomViewFilter::class, 'custom_view_id');
    }

    public function custom_view_sorts()
    {
        return $this->hasMany(CustomViewSort::class, 'custom_view_id')->orderBy('priority');
    }

    public function custom_view_summaries()
    {
        return $this->hasMany(CustomViewSummary::class, 'custom_view_id');
    }

    public function deletingChildren()
    {
        $this->custom_view_columns()->delete();
        $this->custom_view_filters()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            $model->setDefaultFlgInTable();
        });
        static::updating(function ($model) {
            $model->setDefaultFlgInTable();
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
        });
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
                ->display(function ($v) use ($item) {
                    if (is_null($this)) {
                        return '';
                    }
                    return $item->setCustomValue($this)->html();
                });
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
        // get custom view columns
        $custom_view_columns = $this->custom_view_columns;
        
        // create headers
        $headers = [];
        foreach ($custom_view_columns as $custom_view_column) {
            $headers[] = $custom_view_column
                ->column_item
                ->label(array_get($custom_view_column, 'view_column_name'))
                ->label();
        }
        $headers[] = trans('admin.action');
        
        // get table bodies
        $bodies = [];
        if (isset($datalist)) {
            foreach ($datalist as $data) {
                $body_items = [];
                foreach ($custom_view_columns as $custom_view_column) {
                    $item = $custom_view_column->column_item;
                    $body_items[] = $item->setCustomValue($data)->html();
                }

                ///// add show and edit link
                // using role
                $link = (new Linker)
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
                if (isset($options['action_callback'])) {
                    $options['action_callback']($link, $custom_table, $data);
                }
                // add hidden item about data id
                $link .= '<input type="hidden" data-id="'.array_get($data, 'id').'" />';
                $body_items[] = $link;

                // add items to body
                $bodies[] = $body_items;
            }
        }

        //return headers, bodies
        return [$headers, $bodies];
    }

    /**
     * get default view using table
     */
    public static function getDefault($tableObj)
    {
        $user = Admin::user();
        $tableObj = CustomTable::getEloquent($tableObj);

        // get request
        $request = Req::capture();

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
        if (!isset($view) && !is_null($user)) {
            // get suuid
            $suuid = $user->getSettingValue(implode(".", [UserSetting::VIEW, $tableObj->table_name]));
            $view = CustomView::findBySuuid($suuid);
        }
        // if url doesn't contain view query, get custom view. first
        if (!isset($view)) {
            $view = $tableObj->custom_views()->where('default_flg', true)->first();
        }
        if (!isset($view)) {
            $view = $tableObj->custom_views()->first();
        }
        // if form doesn't contain for target table, create view.
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
    
    protected static function createDefaultView($tableObj)
    {
        $view = new CustomView;
        $view->custom_table_id = $tableObj->id;
        $view->view_type = ViewType::SYSTEM;
        $view->view_view_name = exmtrans('custom_view.default_view_name');
        $view->saveOrFail();
        
        return $view;
    }

    protected function createDefaultViewColumns()
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
                $view_column_target = $custom_view_sort->custom_column->getIndexColumnName() ?? null;
            } elseif ($custom_view_sort->view_column_type == ViewColumnType::SYSTEM) {
                $system_info = SystemColumn::getOption(['id' => array_get($custom_view_sort, 'view_column_target_id')]);
                $view_column_target = array_get($system_info, 'sql_name') ?? array_get($system_info, 'name');
            }
            //set order
            $model->orderby($view_column_target, $custom_view_sort->sort == ViewColumnSort::ASC ? 'asc' : 'desc');
        }

        return $model;
    }

    /**
     * set value summary
     */
    public function getValueSummary($model, $table_name)
    {
        // get table id
        $db_table_name = getDBTableName($table_name);

        $group_columns = [];
        $custom_tables = [];

        // set grouping columns
        foreach ($this->custom_view_columns as $custom_view_column) {
            $item = $custom_view_column->column_item;

            // first, set group_column. this column's name uses index.
            $group_columns[] = $item->sqlname();
            // parent_id need parent_type
            if ($item instanceof \Exceedone\Exment\ColumnItems\ParentItem) {
                $group_columns[] = $item->sqltypename();
            }

            $alter_column_index = ViewKindType::DEFAULT . '_' . $custom_view_column->id;

            $this->setSummaryItem($item, $alter_column_index, $custom_tables);
        }
        // set summary columns
        foreach ($this->custom_view_summaries as $custom_view_summary) {
            $item = $custom_view_summary->column_item;

            $alter_column_index = ViewKindType::AGGREGATE . '_' . $custom_view_summary->id;
            $this->setSummaryItem($item, $alter_column_index, $custom_tables, $custom_view_summary->view_summary_condition);
        }

        // set filter columns
        foreach ($this->custom_view_filters as $custom_view_filter) {
            $custom_table_id = array_get($custom_view_filter, 'view_column_table_id');

            if (array_key_exists($custom_table_id, $custom_tables)) {
                $custom_tables[$custom_table_id]['filter'][] = $custom_view_filter;
            } else {
                $custom_tables[$custom_table_id] = [
                    'table_name' => getDBTableName($custom_table_id),
                    'filter' => [$custom_view_filter]
                ];
            }
        }

        $sub_queries = [];
        // get relation parent tables
        $parent_relations = CustomRelation::getRelationsByChild($this->custom_table);
        // get relation child tables
        $child_relations = CustomRelation::getRelationsByParent($this->custom_table);
        // join select table refered from this table.
        $select_table_columns = $this->custom_table->getSelectTables();
        // join table refer to this table as select.
        $selected_table_columns = $this->custom_table->getSelectedTables();

        $custom_table_id = $this->custom_table->id;

        foreach ($custom_tables as $table_id => $custom_table) {
            // add select column and filter
            if ($table_id == $custom_table_id) {
                $this->addQuery($model, $db_table_name, $custom_table);
                continue;
            }
            // join parent table
            if ($parent_relations->contains(function ($value, $key) use ($table_id) {
                return $value->parent_custom_table->id == $table_id;
            })) {
                $this->addQuery($model, $db_table_name, $custom_table, 'parent_id', 'id');
                continue;
            }
            // create subquery grouping child table
            if ($child_relations->contains(function ($value, $key) use ($table_id) {
                return $value->child_custom_table->id == $table_id;
            })) {
                $sub_query = $this->getSubQuery($db_table_name, 'id', 'parent_id', $custom_table);
                if (array_key_exists('select_group', $custom_table)) {
                    $model = $model->addSelect($custom_table['select_group']);
                }
                $sub_queries[] = $sub_query;
                continue;
            }
            // join table refered from target table
            if (in_array($table_id, $select_table_columns)) {
                $column_key = array_search($table_id, $select_table_columns);
                $this->addQuery($model, $db_table_name, $custom_table, $column_key, 'id');
                continue;
            }
            // create subquery grouping table refer to target table
            if (in_array($table_id, $selected_table_columns)) {
                $column_key = array_search($table_id, $selected_table_columns);
                $sub_query = $this->getSubQuery($db_table_name, 'id', $column_key, $custom_table);
                if (array_key_exists('select_group', $custom_table)) {
                    $model = $model->addSelect($custom_table['select_group']);
                }
                $sub_queries[] = $sub_query;
                continue;
            }
        }

        // join subquery
        foreach ($sub_queries as $table_no => $sub_query) {
            $model = $model->leftjoin(\DB::raw('('.$sub_query->toSql().") As table_$table_no"), $db_table_name.'.id', "table_$table_no.id");
            $model = $model->mergeBindings($sub_query);
        }

        // set sql grouping columns
        $model = $model->groupBy($group_columns);

        $datalist = $model->get();

        return $datalist;
    }
    /**
     * set summary grid item
     */
    protected function setSummaryItem($item, $index, &$custom_tables, $summary_condition = null)
    {
        $item->options([
            'summary' => true,
            'summary_condition' => $summary_condition,
            'summary_index' => $index
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
    }
    /**
     * add select column and filter and join table to main query
     */
    protected function addQuery(&$model, $table_main, $custom_table, $key_main = null, $key_sub = null)
    {
        $table_name = array_get($custom_table, 'table_name');
        if ($table_name != $table_main) {
            $model = $model->join($table_name, "$table_main.$key_main", "$table_name.$key_sub");
            $model = $model->whereNull("$table_name.deleted_at");
        }
        if (array_key_exists('select', $custom_table)) {
            $model = $model->addSelect($custom_table['select']);
        }
        if (array_key_exists('filter', $custom_table)) {
            foreach ($custom_table['filter'] as $filter) {
                $filter->setValueFilter($model, $table_name);
            }
        }
    }
    /**
     * add select column and filter and join table to sub query
     */
    protected function getSubQuery($table_main, $key_main, $key_sub, $custom_table)
    {
        $table_name = array_get($custom_table, 'table_name');
        $sub_query = \DB::table($table_main)
            ->select("$table_main.id")
            ->join($table_name, "$table_main.$key_main", "$table_name.$key_sub")
            ->whereNull("$table_name.deleted_at")
            ->groupBy("$table_main.id");
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
    
    /**
     * import template
     */
    public static function importTemplate($view, $options = [])
    {
        $custom_table = CustomTable::getEloquent(array_get($view, 'table_name'));
        $findArray = [
            'custom_table_id' => $custom_table->id
        ];
        // if set suuid in json, set suuid(for dashbrord list)
        if (array_key_value_exists('suuid', $view)) {
            $findArray['suuid'] =  array_get($view, 'suuid');
        } else {
            $findArray['suuid'] =  short_uuid();
        }
        // Create view --------------------------------------------------
        $custom_view = CustomView::firstOrNew($findArray);
        $custom_view->custom_table_id = $custom_table->id;
        $custom_view->suuid = $findArray['suuid'];
        $custom_view->view_kind_type = ViewKindType::getEnumValue(array_get($view, 'view_kind_type'), ViewKindType::DEFAULT());
        $custom_view->view_type = ViewType::getEnumValue(array_get($view, 'view_type'), ViewType::SYSTEM());
        $custom_view->view_view_name = array_get($view, 'view_view_name');
        $custom_view->default_flg = boolval(array_get($view, 'default_flg'));
        $custom_view->saveOrFail();
        
        // create view columns --------------------------------------------------
        if (array_key_exists('custom_view_columns', $view)) {
            foreach (array_get($view, "custom_view_columns") as $view_column) {
                CustomViewColumn::importTemplate($view_column, [
                    'custom_table' => $custom_table,
                    'custom_view' => $custom_view,
                ]);
            }
        }
        
        // create view filters --------------------------------------------------
        if (array_key_exists('custom_view_filters', $view)) {
            foreach (array_get($view, "custom_view_filters") as $view_column) {
                CustomViewFilter::importTemplate($view_column, [
                    'custom_table' => $custom_table,
                    'custom_view' => $custom_view,
                ]);
            }
        }
        
        // create view sorts --------------------------------------------------
        if (array_key_exists('custom_view_sorts', $view)) {
            foreach (array_get($view, "custom_view_sorts") as $view_column) {
                CustomViewSort::importTemplate($view_column, [
                    'custom_table' => $custom_table,
                    'custom_view' => $custom_view,
                ]);
            }
        }

        // create view summary --------------------------------------------------
        if (array_key_exists('custom_view_summaries', $view)) {
            foreach (array_get($view, "custom_view_summaries") as $view_column) {
                CustomViewSummary::importTemplate($view_column, [
                    'custom_table' => $custom_table,
                    'custom_view' => $custom_view,
                ]);
            }
        }

        return $custom_view;
    }
}
