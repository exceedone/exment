<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Carbon\Carbon;

class CustomViewFilter extends ModelBase
{
    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'view_filter_condition_value'];
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    /**
     * get edited view_filter_condition_value_text.
     */
    public function getViewFilterConditionValueAttribute()
    {
        if (is_string($this->view_filter_condition_value_text)) {
            $array = json_decode($this->view_filter_condition_value_text);
            if (is_array($array)) {
                return array_filter($array, function ($val) {
                    return !is_null($val);
                });
            }
        }
        return $this->view_filter_condition_value_text;
    }
    
    /**
     * set view_filter_condition_value_text.
     * * we have to convert int if view_filter_condition_value is array*
     */
    public function setViewFilterConditionValueAttribute($view_filter_condition_value)
    {
        if (is_array($view_filter_condition_value)) {
            $array = array_filter($view_filter_condition_value, function ($val) {
                return !is_null($val);
            });
            $this->view_filter_condition_value_text = json_encode($array);
        } else {
            $this->view_filter_condition_value_text = $view_filter_condition_value;
        }
    }

    public static $templateItems = [
        'excepts' => [
            'import' => ['view_column_table_id', 'view_column_target', 'custom_column'],
            'export' => ['view_column_table_id', 'view_column_target_id', 'custom_view_id', 'view_column_target', 'custom_column', 'view_filter_condition_value_table_id', 'view_filter_condition_value_id'],
        ],
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
        $condition_value_text = $this->view_filter_condition_value_text;
        $view_filter_condition = $this->view_filter_condition;
        if ($this->view_column_type == ViewColumnType::COLUMN) {
            $view_column_target = CustomColumn::getEloquent($view_column_target)->getIndexColumnName() ?? null;
        } elseif ($this->view_column_type == ViewColumnType::PARENT_ID) {
            //TODO: set as 1:n. develop as n:n
            $view_column_target = 'parent_id';
        } elseif ($this->view_column_type == ViewColumnType::WORKFLOW) {
            // TODO filter for workflow
            return $model->workflowStatus($view_filter_condition, $condition_value_text);
        } elseif ($this->view_column_type == ViewColumnType::SYSTEM) {
            $view_column_target = SystemColumn::getOption(['id' => $view_column_target])['sqlname'] ?? null;
        }
        
        if (isset($db_table_name)) {
            $view_column_target = $db_table_name.'.'.$view_column_target;
        }
        // get filter condition
        switch ($view_filter_condition) {
            // equal
            case ViewColumnFilterOption::EQ:
            case ViewColumnFilterOption::USER_EQ:
                $model = $model->where($view_column_target, $condition_value_text);
                break;
            // not equal
            case ViewColumnFilterOption::NE:
            case ViewColumnFilterOption::USER_NE:
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

            // for number --------------------------------------------------
            // greater
            case ViewColumnFilterOption::NUMBER_GT:
            case ViewColumnFilterOption::NUMBER_LT:
            case ViewColumnFilterOption::NUMBER_GTE:
            case ViewColumnFilterOption::NUMBER_LTE:
                $condition_value_text = str_replace(',', '', $condition_value_text);
                if (preg_match('/^([1-9]\d*|0)\.(\d+)?$/', $condition_value_text)) {
                    $condition_value_text = floatval($condition_value_text);
                } else {
                    $condition_value_text = intval($condition_value_text);
                }
                switch ($view_filter_condition) {
                    case ViewColumnFilterOption::NUMBER_GT:
                        $model = $model->where($view_column_target, '>', $condition_value_text);
                        break;
                    case ViewColumnFilterOption::NUMBER_LT:
                        $model = $model->where($view_column_target, '<', $condition_value_text);
                        break;
                    case ViewColumnFilterOption::NUMBER_GTE:
                        $model = $model->where($view_column_target, '>=', $condition_value_text);
                        break;
                    case ViewColumnFilterOption::NUMBER_LTE:
                        $model = $model->where($view_column_target, '<=', $condition_value_text);
                        break;
                }
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
                        $value_day = Carbon::tomorrow();
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
            case ViewColumnFilterOption::DAY_ON_OR_AFTER:
            case ViewColumnFilterOption::DAY_ON_OR_BEFORE:
            case ViewColumnFilterOption::DAY_TODAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_TODAY_OR_BEFORE:
            case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_BEFORE:
            case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                $today = Carbon::today();
                // get target day and where mark
                switch ($view_filter_condition) {
                    case ViewColumnFilterOption::DAY_ON_OR_AFTER:
                        $target_day = Carbon::parse($condition_value_text);
                        $mark = ">=";
                        break;
                    case ViewColumnFilterOption::DAY_ON_OR_BEFORE:
                        $target_day = Carbon::parse($condition_value_text);
                        $mark = "<=";
                        break;
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
                
            // for select --------------------------------------------------
            case ViewColumnFilterOption::SELECT_EXISTS:
                $raw = "JSON_SEARCH($view_column_target, 'one', '$condition_value_text')";
                $model = $model->where(function ($query) use ($view_column_target, $raw) {
                    $query->where($view_column_target, 'LIKE', '[%]')
                          ->whereNotNull(\DB::raw($raw));
                })->orWhere($view_column_target, $condition_value_text);
                break;
            case ViewColumnFilterOption::SELECT_NOT_EXISTS:
                $raw = "JSON_SEARCH($view_column_target, 'one', '$condition_value_text')";
                $model = $model->where(function ($query) use ($view_column_target, $raw) {
                    $query->where($view_column_target, 'LIKE', '[%]')
                        ->whereNull(\DB::raw($raw));
                })->orWhere($view_column_target, '<>', $condition_value_text);
                break;
        
            // for user --------------------------------------------------
            case ViewColumnFilterOption::USER_EQ_USER:
                $model = $model->where($view_column_target, \Exment::user()->base_user->id);
                break;
            case ViewColumnFilterOption::USER_NE_USER:
                $model = $model->where($view_column_target, '<>', \Exment::user()->base_user->id);
        }

        return $model;
    }
}
