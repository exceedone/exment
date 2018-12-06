<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Grid;
use Encore\Admin\Grid\Column as GridColumn;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewColumnSystem;
use Exceedone\Exment\Enums\ViewColumnSort;
use Exceedone\Exment\Enums\UserSetting;
use Illuminate\Http\Request as Req;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Auth\Authenticatable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\AuthorityType;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Carbon\Carbon;


class CustomView extends ModelBase
{
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
            $view_column_target = array_get($custom_view_column, 'view_column_target');
            // if tagret is number, column type is column.
            if (is_numeric($view_column_target)) {
                $column = $custom_view_column->custom_column;
                if(!isset($column)){continue;}
                //$column_name = $column->getIndexColumnName();
                $column_name = array_get($column, 'column_name');
                $column_type = array_get($column, 'column_type');
                $column_view_name = array_get($column, 'column_view_name');

                $grid->column($column_name, $column_view_name)->display(function ($v) use ($column) {
                    if (is_null($this)) {
                        return '';
                    }
                    $isUrl = in_array(array_get($column, 'column_type'), ['url', 'select_table']);
                    if ($isUrl) {
                        return $this->getColumnUrl($column, true);
                    }
                    return esc_html($this->getValue($column, true));
                });
            }
            // parent_id
            elseif ($view_column_target == ViewColumnSystem::PARENT_ID) {
                // get parent data
                $relation = CustomRelation
                    ::with('parent_custom_table')
                    ->where('child_custom_table_id', $this->custom_table->id)
                    ->first();
                if (isset($relation)) {
                    $grid->column(ViewColumnSystem::PARENT_ID, $relation->parent_custom_table->table_view_name)
                        ->sortable()
                        ->display(function ($value) {
                            // get parent_type
                            $parent_type = $this->parent_type;
                            if (is_null($parent_type)) {
                                return null;
                            }
                            // get value
                            $custom_value = getModelName($parent_type)::find($value);
                            return $custom_value->getUrl(true);
                        });
                }
            }
            // system column
            else {
                // get column name
                $grid->column($view_column_target, exmtrans("common.$view_column_target"))->sortable()
                    ->display(function ($value) use ($view_column_target) {
                        if (!is_null($value)) {
                            return esc_html($value);
                        }
                        // if cannnot get value, return array_get from this
                        return esc_html(array_get($this, $view_column_target));
                    });
            }
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
            // get column --------------------------------------------------
            // if number, get custom column
            if (is_numeric($custom_view_column->view_column_target)) {
                $custom_column = $custom_view_column->custom_column;
                if (isset($custom_column)) {
                    $headers[] = $custom_column->column_view_name;
                }
            } elseif ($custom_view_column->view_column_target == ViewColumnSystem::PARENT_ID) {
                // get parent data
                $relation = CustomRelation
                    ::with('parent_custom_table')
                    ->where('child_custom_table_id', $custom_table->id)
                    ->first();
                if (isset($relation)) {
                    $headers[] = $relation->parent_custom_table->table_view_name;
                }
            } else {
                // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
                $name = collect(ViewColumnType::SYSTEM_OPTIONS())->first(function ($value) use ($custom_view_column) {
                    return array_get($value, 'name') == array_get($custom_view_column, 'view_column_target');
                })['name'] ?? null;
                // add headers transaction
                $headers[] = exmtrans('common.'.$name);
            }
        }
        $headers[] = trans('admin.action');

        // get table bodies
        $bodies = [];
        if (isset($datalist)) {
            foreach ($datalist as $data) {
                $body_items = [];
                foreach ($custom_view_columns as $custom_view_column) {
                    // get column --------------------------------------------------
                    // if number, get custom column
                    if (is_numeric($custom_view_column->view_column_target)) {
                        $custom_column = $custom_view_column->custom_column;
                        if (isset($custom_column)) {
                            $isUrl = in_array(array_get($custom_column, 'column_type'), ['url', 'select_table']);
                            if ($isUrl) {
                                $body_items[] = $data->getColumnUrl($custom_column, true);
                            } else {
                                $body_items[] = esc_html($data->getValue($custom_column, true));
                            }
                        }
                    }
                    // parent id
                    elseif ($custom_view_column->view_column_target == 'parent_id') {
                        // get parent data
                        $relation = CustomRelation
                            ::with('parent_custom_table')
                            ->where('child_custom_table_id', $custom_table->id)
                            ->first();
                        if (isset($relation)) {
                            $body_items[] = getModelName(array_get($data, 'parent_type'))::find(array_get($data, 'parent_id'))->getUrl(true) ?? null;
                        }
                    } else {
                        // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
                        $name = collect(ViewColumnType::SYSTEM_OPTIONS())->first(function ($value) use ($custom_view_column) {
                            return array_get($value, 'name') == array_get($custom_view_column, 'view_column_target');
                        })['name'] ?? null;
                        if (isset($name)) {
                            $body_items[] = esc_html(array_get($data, $name));
                        }
                    }
                }

                ///// add show and edit link
                // using authority
                $link = '<a href="'.admin_base_paths('data', array_get($custom_table, 'table_name'), array_get($data, 'id')).'" style="margin-right:3px;"><i class="fa fa-eye"></i></a>';
                if (Admin::user()->hasPermissionEditData(array_get($data, 'id'), $custom_table->table_name)) {
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

        // if target form doesn't have columns, add columns for search_enabled columns.
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
        $view->view_type = 'system';
        $view->view_view_name = exmtrans('custom_view.default_view_name');
        $view->saveOrFail();
        
        return $view;
    }

    protected function createDefaultViewColumns()
    {
        $view_columns = [];
        // set default view_column
        foreach (ViewColumnType::SYSTEM_OPTIONS() as $view_column_system) {
            // if not default, continue
            if (!boolval(array_get($view_column_system, 'default'))) {
                continue;
            }
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
    public function setValueFilter($model){
        foreach ($this->custom_view_filters as $filter) {
            // get filter target column
            $view_filter_target = $filter->view_filter_target;
            if (is_numeric($view_filter_target)) {
                $view_filter_target = CustomColumn::find($view_filter_target)->getIndexColumnName() ?? null;
            }
            $condition_value_text = $filter->view_filter_condition_value_text;
            $view_filter_condition = $filter->view_filter_condition;
            // get filter condition
            switch ($view_filter_condition) {
                // equal
                case ViewColumnFilterOption::EQ:
                    $model = $model->where($view_filter_target, $condition_value_text);
                    break;
                // not equal
                case ViewColumnFilterOption::NE:
                    $model = $model->where($view_filter_target, '<>', $condition_value_text);
                    break;
                // not null
                case ViewColumnFilterOption::NOT_NULL:
                case ViewColumnFilterOption::DAY_NOT_NULL:
                case ViewColumnFilterOption::USER_NOT_NULL:
                    $model = $model->whereNotNull($view_filter_target);
                    break;
                // null
                case ViewColumnFilterOption::NULL:
                case ViewColumnFilterOption::DAY_NULL:
                case ViewColumnFilterOption::USER_NULL:
                    $model = $model->whereNull($view_filter_target);
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
                    $model = $model->whereDate($view_filter_target, $value_day);
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
                        ->whereYear($view_filter_target, $value_day->year)
                        ->whereMonth($view_filter_target, $value_day->month);
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
                    $model = $model->whereYear($view_filter_target, $value_day->year);
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
                    $model = $model->whereDate($view_filter_target, $mark, $target_day);
                    break;
                    
                // for user --------------------------------------------------
                case ViewColumnFilterOption::USER_EQ_USER:
                    $model = $model->where($view_filter_target, Admin::user()->base_user()->id);
                    break;
                case ViewColumnFilterOption::USER_NE_USER:
                    $model = $model->where($view_filter_target, '<>', Admin::user()->base_user()->id);
                       
            }
        }

        return $model;
    }
    
    /**
     * set value sort 
     */
    public function setValueSort($model){
        // if request has "_sort", not executing
        if(\Request::capture()->has('_sort')){
            return $model;
        }
        foreach ($this->custom_view_sorts as $custom_view_sort) {
            // get column target column
            $view_column_target = $custom_view_sort->view_column_target;
            if (is_numeric($view_column_target)) {
                $view_column_target = CustomColumn::find($view_column_target)->getIndexColumnName() ?? null;
            }
            //set order
            $model->orderby($view_column_target, $custom_view_sort->sort == ViewColumnSort::ASC ? 'asc' : 'desc');
        }

        return $model;
    }
}
