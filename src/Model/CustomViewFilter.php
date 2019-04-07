<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Carbon\Carbon;

class CustomViewFilter extends ModelBase
{
    protected $guarded = ['id'];
    protected $appends = ['view_column_target'];
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    protected static $templateItems = [
        'excepts' => ['view_column_table_id', 'view_column_target_id', 'custom_view_id', 'view_column_target', 'custom_column'],
        'uniqueKeys' => [
            'custom_view_id', 'view_column_type', 'view_column_target_id', 'view_column_table_id', 'view_filter_condition'
        ],
        'parent' => 'custom_view_id',
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'view_column_table_name',
                            'column_name' => 'view_column_target_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
            ],
        ],
        'enums' => [
            'view_column_type' => ViewColumnType::class,
            'view_filter_condition' => ViewColumnFilterOption::class,
        ],
    ];

    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function custom_column()
    {
        if ($this->view_column_type == ViewColumnType::SYSTEM) {
            return null;
        }
        return $this->belongsTo(CustomColumn::class, 'view_column_target_id');
    }
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'view_column_table_id');
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * set value filter
     */
    public function setValueFilter($model, $db_table_name = null)
    {
        // get filter target column
        $view_column_target = $this->view_column_target_id;
        if ($this->view_column_type == ViewColumnType::COLUMN) {
            $view_column_target = CustomColumn::getEloquent($view_column_target)->getIndexColumnName() ?? null;
        } elseif ($this->view_column_type == ViewColumnType::PARENT_ID) {
            //TODO: set as 1:n. develop as n:n
            $view_column_target = 'parent_id';
        } elseif ($this->view_column_type == ViewColumnType::SYSTEM) {
            $view_column_target = SystemColumn::getOption(['id' => $view_column_target])['name'] ?? null;
        }
        
        if (isset($db_table_name)) {
            $view_column_target = $db_table_name.'.'.$view_column_target;
        }
        $condition_value_text = $this->view_filter_condition_value_text;
        $view_filter_condition = $this->view_filter_condition;
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
            case ViewColumnFilterOption::DAY_TODAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_TODAY_OR_BEFORE:
            case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_BEFORE:
            case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                $today = Carbon::today();
                // get target day and where mark
                switch ($view_filter_condition) {
                    case ViewColumnFilterOption::DAY_TODAY_OR_AFTER:
                        $target_day = $today;
                        $mark = ">=";
                        break;
                    case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_AFTER:
                        $target_day = $today->addDay(-1 * intval($condition_value_text));
                        $mark = ">=";
                        break;
                    case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_AFTER:
                        $target_day = $today->addDay(intval($condition_value_text));
                        $mark = ">=";
                        break;
                    case ViewColumnFilterOption::DAY_TODAY_OR_BEFORE:
                        $target_day = $today;
                        $mark = "<=";
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

        return $model;
    }
}
