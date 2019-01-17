<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request as Req;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewColumnSort;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\SystemColumn;
use Carbon\Carbon;

class CustomView extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DefaultFlgTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = ['id', 'suuid'];

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
     * set laravel-admin grid using custom_view
     */
    public function setGrid($grid)
    {
        $custom_table = $this->custom_table;
        // get view columns
        $custom_view_columns = $this->custom_view_columns;
        foreach ($custom_view_columns as $custom_view_column) {
            $item = $custom_view_column->column_item;
            $grid->column($item->name(), $item->label())
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
            $headers[] = $custom_view_column->column_item->label();
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
                $link = '<a href="'.admin_base_paths('data', array_get($custom_table, 'table_name'), array_get($data, 'id')).'" style="margin:0 3px;"><i class="fa fa-eye"></i></a>';
                if ($custom_table->hasPermissionEditData(array_get($data, 'id'))) {
                    $link .= '<a href="'.admin_base_paths('data', array_get($custom_table, 'table_name'), array_get($data, 'id'), 'edit').'"><i class="fa fa-edit"></i></a>';
                }
                if(isset($options['action_callback'])){
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
        $view->view_type = Enums\ViewType::SYSTEM;
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
     * set value filter 
     */
    public function setValueFilter($model, $table_name = null){
        foreach ($this->custom_view_filters as $filter) {
            // get filter target column
            $view_column_target = $filter->view_column_target;
            if ($filter->view_column_type == ViewColumnType::COLUMN) {
                $view_column_target = CustomColumn::find($view_column_target)->getIndexColumnName() ?? null;
            }elseif($filter->view_column_type == ViewColumnType::PARENT_ID) {
                //TODO: set as 1:n. develop as n:n
                $view_column_target = 'parent_id';
            }
            if (isset($table_name)) {
                $view_column_target = $table_name.'.'.$view_column_target;
            }
            $condition_value_text = $filter->view_filter_condition_value_text;
            $view_filter_condition = $filter->view_filter_condition;
            // get filter condition
            switch ($view_filter_condition) {
                // equal
                case ViewColumnFilterOption::EQ:
                    $model = $model->where($view_column_target, $condition_value_text);
                    break;
                // not equal
                case ViewColumnFilterOption::NE:
                    $model = $model->where($view_column_target, '<>', $condition_value_text);
                    break;
                // not null
                case ViewColumnFilterOption::NOT_NULL:
                case ViewColumnFilterOption::DAY_NOT_NULL:
                case ViewColumnFilterOption::USER_NOT_NULL:
                    $model = $model->whereNotNull($view_column_target);
                    break;
                // null
                case ViewColumnFilterOption::NULL:
                case ViewColumnFilterOption::DAY_NULL:
                case ViewColumnFilterOption::USER_NULL:
                    $model = $model->whereNull($view_column_target);
                    break;
                
                // for date --------------------------------------------------
                // date equal day
                case ViewColumnFilterOption::DAY_ON:
                case ViewColumnFilterOption::DAY_YESTERDAY:
                case ViewColumnFilterOption::DAY_TODAY:
                case ViewColumnFilterOption::DAY_TOMORROW:
                    // get target day
                    switch ($view_filter_condition) {
                        case ViewColumnFilterOption::DAY_ON:
                            $value_day = Carbon::parse($condition_value_text);
                            break;
                        case ViewColumnFilterOption::DAY_YESTERDAY:
                            $value_day = Carbon::yesterday();
                            break;
                        case ViewColumnFilterOption::DAY_TODAY:
                            $value_day = Carbon::today();
                            break;
                        case ViewColumnFilterOption::DAY_TOMORROW:
                            $value_day = Carbon::tomorow();
                            break;
                    }
                    $model = $model->whereDate($view_column_target, $value_day);
                    break;
                    
                // date equal month
                case ViewColumnFilterOption::DAY_THIS_MONTH:
                case ViewColumnFilterOption::DAY_LAST_MONTH:
                case ViewColumnFilterOption::DAY_NEXT_MONTH:
                    // get target month
                    switch ($view_filter_condition) {
                        case ViewColumnFilterOption::DAY_THIS_MONTH:
                            $value_day = new Carbon('first day of this month');
                            break;
                        case ViewColumnFilterOption::DAY_LAST_MONTH:
                            $value_day = new Carbon('first day of last month');
                            break;
                        case ViewColumnFilterOption::DAY_NEXT_MONTH:
                            $value_day = new Carbon('first day of next month');
                            break;
                    }
                    $model = $model
                        ->whereYear($view_column_target, $value_day->year)
                        ->whereMonth($view_column_target, $value_day->month);
                    break;
                    
                // date equal year
                case ViewColumnFilterOption::DAY_THIS_YEAR:
                case ViewColumnFilterOption::DAY_LAST_YEAR:
                case ViewColumnFilterOption::DAY_NEXT_YEAR:
                    // get target year
                    switch ($view_filter_condition) {
                        case ViewColumnFilterOption::DAY_THIS_YEAR:
                            $value_day = new Carbon('first day of this year');
                            break;
                        case ViewColumnFilterOption::DAY_LAST_YEAR:
                            $value_day = new Carbon('first day of last year');
                            break;
                        case ViewColumnFilterOption::DAY_NEXT_YEAR:
                            $value_day = new Carbon('first day of next year');
                            break;
                    }
                    $model = $model->whereYear($view_column_target, $value_day->year);
                    break;
                    
                // date and X days before or after
                case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_AFTER:
                case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_AFTER:
                case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_BEFORE:
                case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                    $today = Carbon::today();
                    // get target day and where mark
                    switch ($view_filter_condition) {
                        case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_AFTER:
                            $target_day = $today->addDay(-1 * intval($condition_value_text));
                            $mark = ">=";
                            break;
                        case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_AFTER:
                            $target_day = $today->addDay(intval($condition_value_text));
                            $mark = ">=";
                            break;
                        case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_BEFORE:
                            $target_day = $today->addDay(-1 * intval($condition_value_text));
                            $mark = "<=";
                            break;
                        case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                            $target_day = $today->addDay(intval($condition_value_text));
                            $mark = "<=";
                            break;
                    }
                    $model = $model->whereDate($view_column_target, $mark, $target_day);
                    break;
                    
                // for user --------------------------------------------------
                case ViewColumnFilterOption::USER_EQ_USER:
                    $model = $model->where($view_column_target, Admin::user()->base_user()->id);
                    break;
                case ViewColumnFilterOption::USER_NE_USER:
                    $model = $model->where($view_column_target, '<>', Admin::user()->base_user()->id);
            }
        }

        return $model;
    }

    /**
     * set value sort 
     */
    public function setValueSort($model){
        // if request has "_sort", not executing
        if(request()->has('_sort')){
            return $model;
        }
        foreach ($this->custom_view_sorts as $custom_view_sort) {
            // get column target column
            if ($custom_view_sort->view_column_type == ViewColumnType::COLUMN) {
                $view_column_target = $custom_view_sort->custom_column->getIndexColumnName() ?? null;
            }
            elseif ($custom_view_sort->view_column_type == ViewColumnType::SYSTEM) {
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
    public function getValueSummary($model, $table_name){
        // get table id
        $table_id = getDBTableName($table_name);

        // get join tables
        $relations = CustomRelation::getRelationsByParent($table_name);
        foreach($relations as $relation){
            $child_name = getDBTableName($relation->child_custom_table);
            $model = $model->join($child_name, $table_id.'.id', "$child_name.parent_id");
            $model = $model->where("$child_name.parent_type", $table_name);
        }

        // set filter
        $model = $this->setValueFilter($model, $table_id);

        $group_columns = [];
        $select_columns = [];
        $index = 0;
        
        // set grouping columns
        foreach ($this->custom_view_columns as $custom_view_column) {
            $view_column_type = array_get($custom_view_column, 'view_column_type');
            $alter_column_id = 'column_' . ViewKindType::DEFAULT . '_' . $custom_view_column->id;
            if ($view_column_type == ViewColumnType::COLUMN) {
                $column = $custom_view_column->custom_column;
                if(!isset($column)){
                    continue;
                }
                // get virtual column name
                $column_name = $column->getIndexColumnName();
                // $column_view_name = is_nullorempty(array_get($custom_view_column, 'view_column_name'))? 
                //     array_get($column, 'column_view_name') : array_get($custom_view_column, 'view_column_name');

                $group_columns[] = $column_name;
                $select_columns[] = "$column_name as $alter_column_id";

                $index++;
            }
            elseif ($view_column_type == ViewColumnType::SYSTEM) {
                $system_info = SystemColumn::getOption(['id' => array_get($custom_view_column, 'view_column_target_id')]);
                $view_column_target = array_get($system_info, 'sql_name') ?? array_get($system_info, 'name');
                
                $group_columns[] = "$table_id.$view_column_target";
                $select_columns[] = "$table_id.$view_column_target as $alter_column_id";
            }
        }
        // set summary columns
        foreach ($this->custom_view_summaries as $custom_view_summary) {
            $column = $custom_view_summary->custom_column;
            $alter_column_id = 'column_' . ViewKindType::AGGREGATE . '_' . $custom_view_summary->id;
            if (!isset($column)) {
                continue;
            }
            $column_table_name = getDBTableName($column->custom_table);
            $column_name = $column->column_name;
            // $column_view_name = is_nullorempty(array_get($custom_view_summary, 'view_column_name'))? 
            //     array_get($column, 'column_view_name') : array_get($custom_view_summary, 'view_column_name');

            $summary = 'sum';
            switch($custom_view_summary->view_summary_condition) {
                case 1:
                    $summary = 'sum';
                    break;
                case 2:
                    $summary = 'avg';
                    break;
                case 3:
                    $summary = 'count';
                    break;
            }
            $select_columns[] = \DB::raw("$summary($column_table_name.value->'$.$column_name') AS $alter_column_id");
            $index++;
        }
 
        // set sql select columns
        $model = $model->select($select_columns);
 
        // set sql grouping columns
        $model = $model->groupBy($group_columns);

        $datalist = $model->get();

        return $datalist;
    }

    /**
     * get columns select options. It contains system column(ex. id, suuid, created_at, updated_at), and table columns.
     * @param $number_only
     */
    public function getColumnsSelectOptions($number_only = false)
    {
        $options = [];
        
        foreach($this->custom_view_columns as $custom_view_column) {
            $option = $this->getSelectColumn(ViewKindType::DEFAULT, $custom_view_column, $number_only);
            if (!is_null($option)) {
                $options[] = $option;
            }
        }

        foreach($this->custom_view_summaries as $custom_view_summary) {
            $option = $this->getSelectColumn(ViewKindType::AGGREGATE, $custom_view_summary, $number_only);
            if (!is_null($option)) {
                $options[] = $option;
            }
        }

        return $options;
    }

    public function getSelectColumn($column_type, $custom_view_column, $number_only)
    {
        $view_column_type = array_get($custom_view_column, 'view_column_type');
        $view_column_id = $column_type . '_' . array_get($custom_view_column, 'id');

        $custom_table_id = $this->custom_table_id;
        $column_view_name = array_get($custom_view_column, 'view_column_name');

        switch($view_column_type) {
            case ViewColumnType::COLUMN:
            case ViewColumnType::CHILD_SUM:
                $column = $custom_view_column->custom_column;
                if ($number_only) {
                    switch (array_get($column, 'column_type')) {
                        case ColumnType::INTEGER:
                        case ColumnType::DECIMAL:
                        case ColumnType::CURRENCY:
                            break;
                        default:
                            return null;
                    }
                }
                if (is_nullorempty($column_view_name)) {
                    $column_view_name = array_get($column, 'column_view_name');
                    if ($custom_table_id != array_get($column, 'custom_table_id')) {
                        $column_view_name = array_get($column->custom_table, 'table_view_name') . '::' . $column_view_name;
                    }
                }
                break;
            case ViewColumnType::SYSTEM:
                if ($number_only) return null;
                $system_info = SystemColumn::getOption(['id' => array_get($custom_view_column, 'view_column_target_id')]);
                if (is_nullorempty($column_view_name)) {
                    $column_view_name = exmtrans('common.'.$system_info['name']);
                }
            case ViewColumnType::PARENT_ID:
                if ($number_only) return null;
                $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->custom_table_id)->first();
                ///// if this table is child relation(1:n), add parent table
                if (isset($relation)) {
                    $column_view_name = array_get($relation, 'parent_custom_table.table_view_name');
                }
        }

        if (is_nullorempty($column_view_name)) {
            return null;
        } else {
            return ['id' => $view_column_id, 'text' => $column_view_name];
        }
    }
}
