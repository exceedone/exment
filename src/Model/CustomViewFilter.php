<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\ColumnItems\WorkflowItem;
use Carbon\Carbon;

class CustomViewFilter extends ModelBase
{
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'view_filter_condition_value'];
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => [
            'import' => ['custom_table', 'view_column_table_id', 'view_column_target', 'custom_column'],
            'export' => ['custom_table', 'view_column_table_id', 'view_column_target_id', 'custom_view_id', 'view_column_target', 'custom_column', 'view_filter_condition_value_table_id', 'view_filter_condition_value_id'],
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
            'view_column_type' => ConditionType::class,
            'view_filter_condition' => FilterOption::class,
        ],
    ];

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

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    public function getViewPivotColumnIdAttribute()
    {
        return $this->getViewPivotIdTrait('view_pivot_column_id');
    }
    public function setViewPivotColumnIdAttribute($view_pivot_column_id)
    {
        return $this->setViewPivotIdTrait('view_pivot_column_id', $view_pivot_column_id);
    }
    
    public function getViewPivotTableIdAttribute()
    {
        return $this->getViewPivotIdTrait('view_pivot_table_id');
    }
    public function setViewPivotTableIdAttribute($view_pivot_table_id)
    {
        return $this->setViewPivotIdTrait('view_pivot_table_id', $view_pivot_table_id);
    }
    
    
    /**
     * set value filter
     */
    public function setValueFilter($model, $db_table_name = null, $or_option = false)
    {
        // get filter target column
        $view_column_target = $this->view_column_target_id;
        $condition_value_text = $this->view_filter_condition_value_text;
        $view_filter_condition = $this->view_filter_condition;
        $method_name = $or_option ? 'orWhere': 'where';
        $isDateTime = false;
        $isMultiple = false;
        
        if ($this->view_column_type == ConditionType::WORKFLOW) {
            return WorkflowItem::scopeWorkflow($model, $this->view_column_target_id, $this->custom_table, $view_filter_condition, $condition_value_text, $or_option);
        }

        if ($this->view_column_type == ConditionType::COLUMN) {
            $column_column = CustomColumn::getEloquent($view_column_target);
            $view_column_target = isset($column_column) ? $column_column->getIndexColumnName() : null;
            $isDateTime = isset($column_column) ? isMatchString($column_column->column_type, ColumnType::DATETIME) : false;
            $isMultiple = isset($column_column) ? $column_column->column_item->isMultipleEnabled() : false;
        } elseif ($this->view_column_type == ConditionType::PARENT_ID) {
            //TODO: set as 1:n. develop as n:n
            $view_column_target = 'parent_id';
        } elseif ($this->view_column_type == ConditionType::SYSTEM) {
            $systemOption = SystemColumn::getOption(['id' => $view_column_target]);
            $view_column_target = $systemOption['sqlname'] ?? null;
            $isDateTime = isMatchString($systemOption['type'] ?? null, 'datetime');
        }

        if (!isset($view_column_target)) {
            return;
        }
        if (isset($db_table_name)) {
            $view_column_target = $db_table_name.'.'.$view_column_target;
        }
        if (isset($this->view_group_condition)) {
            // wraped
            $view_column_target = \DB::getQueryGrammar()->getDateFormatString($this->view_group_condition, $view_column_target, false);
            $view_column_target = \DB::raw($view_column_target);
        }
        $condition_value_text = $this->view_filter_condition_value_text;
        $view_filter_condition = $this->view_filter_condition;
        // get filter condition
        switch ($view_filter_condition) {
            // equal
            case FilterOption::EQ:
            case FilterOption::USER_EQ:
                $model->{$method_name}($view_column_target, $condition_value_text);
                break;
            // not equal
            case FilterOption::NE:
            case FilterOption::USER_NE:
                $model->{$method_name}($view_column_target, '<>', $condition_value_text);
                break;
            // not null
            case FilterOption::NOT_NULL:
            case FilterOption::DAY_NOT_NULL:
            case FilterOption::USER_NOT_NULL:
                $model->{$method_name.'NotNull'}($view_column_target);
                break;
            // null
            case FilterOption::NULL:
            case FilterOption::DAY_NULL:
            case FilterOption::USER_NULL:
                $model->{$method_name.'Null'}($view_column_target);
                break;
            
            // like
            case FilterOption::LIKE:
                $condition_value_text = (System::filter_search_type() == FilterSearchType::ALL ? '%' : '') . $condition_value_text . '%';
                $model->{$method_name}($view_column_target, 'LIKE', $condition_value_text);
                break;
            case FilterOption::NOT_LIKE:
                $condition_value_text = (System::filter_search_type() == FilterSearchType::ALL ? '%' : '') . $condition_value_text . '%';
                $model->{$method_name}($view_column_target, 'NOT LIKE', $condition_value_text);
                break;
                
            // for number --------------------------------------------------
            // greater
            case FilterOption::NUMBER_GT:
            case FilterOption::NUMBER_LT:
            case FilterOption::NUMBER_GTE:
            case FilterOption::NUMBER_LTE:
                $condition_value_text = str_replace(',', '', $condition_value_text);
                if (preg_match('/^([1-9]\d*|0)\.(\d+)?$/', $condition_value_text)) {
                    $condition_value_text = floatval($condition_value_text);
                } else {
                    $condition_value_text = intval($condition_value_text);
                }
                switch ($view_filter_condition) {
                    case FilterOption::NUMBER_GT:
                        $model->{$method_name}($view_column_target, '>', $condition_value_text);
                        break;
                    case FilterOption::NUMBER_LT:
                        $model->{$method_name}($view_column_target, '<', $condition_value_text);
                        break;
                    case FilterOption::NUMBER_GTE:
                        $model->{$method_name}($view_column_target, '>=', $condition_value_text);
                        break;
                    case FilterOption::NUMBER_LTE:
                        $model->{$method_name}($view_column_target, '<=', $condition_value_text);
                        break;
                }
                break;
            
            // for date --------------------------------------------------
            // date equal day
            case FilterOption::DAY_ON:
            case FilterOption::DAY_YESTERDAY:
            case FilterOption::DAY_TODAY:
            case FilterOption::DAY_TOMORROW:
                // get target day
                $value_day = null;
                switch ($view_filter_condition) {
                    case FilterOption::DAY_ON:
                        $value_day = Carbon::parse($condition_value_text);
                        break;
                    case FilterOption::DAY_YESTERDAY:
                        $value_day = Carbon::yesterday();
                        break;
                    case FilterOption::DAY_TODAY:
                        $value_day = Carbon::today();
                        break;
                    case FilterOption::DAY_TOMORROW:
                        $value_day = Carbon::tomorrow();
                        break;
                }
                $model->{"{$method_name}DateExment"}($view_column_target, $value_day, $isDateTime);
                break;
                
            // date equal month
            case FilterOption::DAY_THIS_MONTH:
            case FilterOption::DAY_LAST_MONTH:
            case FilterOption::DAY_NEXT_MONTH:
                // get target month
                $value_day = null;
                switch ($view_filter_condition) {
                    case FilterOption::DAY_THIS_MONTH:
                        $value_day = new Carbon('first day of this month');
                        break;
                    case FilterOption::DAY_LAST_MONTH:
                        $value_day = new Carbon('first day of last month');
                        break;
                    case FilterOption::DAY_NEXT_MONTH:
                        $value_day = new Carbon('first day of next month');
                        break;
                }
                $model->{"{$method_name}YearMonthExment"}($view_column_target, $value_day, $isDateTime);
                break;
                
            // date equal year
            case FilterOption::DAY_THIS_YEAR:
            case FilterOption::DAY_LAST_YEAR:
            case FilterOption::DAY_NEXT_YEAR:
                // get target year
                switch ($view_filter_condition) {
                    case FilterOption::DAY_THIS_YEAR:
                        $value_day = new Carbon('first day of this year');
                        break;
                    case FilterOption::DAY_LAST_YEAR:
                        $value_day = new Carbon('first day of last year');
                        break;
                    case FilterOption::DAY_NEXT_YEAR:
                        $value_day = new Carbon('first day of next year');
                        break;
                    default:
                        throw new \Exception; // (Never called this, for set lint).
                }
                $model->{$method_name.'YearExment'}($view_column_target, $value_day->year, $isDateTime);
                break;
                
            // date and X days before or after
            case FilterOption::DAY_ON_OR_AFTER:
            case FilterOption::DAY_ON_OR_BEFORE:
            case FilterOption::DAY_TODAY_OR_AFTER:
            case FilterOption::DAY_TODAY_OR_BEFORE:
            case FilterOption::DAY_LAST_X_DAY_OR_AFTER:
            case FilterOption::DAY_NEXT_X_DAY_OR_AFTER:
            case FilterOption::DAY_LAST_X_DAY_OR_BEFORE:
            case FilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                $today = Carbon::today();
                $target_day = null;
                $mark = null;
                // get target day and where mark
                switch ($view_filter_condition) {
                    case FilterOption::DAY_ON_OR_AFTER:
                        $target_day = Carbon::parse($condition_value_text);
                        $mark = ">=";
                        break;
                    case FilterOption::DAY_ON_OR_BEFORE:
                        $target_day = Carbon::parse($condition_value_text);
                        $mark = "<=";
                        break;
                    case FilterOption::DAY_TODAY_OR_AFTER:
                        $target_day = $today;
                        $mark = ">=";
                        break;
                    case FilterOption::DAY_LAST_X_DAY_OR_AFTER:
                        $target_day = $today->addDay(-1 * intval($condition_value_text));
                        $mark = ">=";
                        break;
                    case FilterOption::DAY_NEXT_X_DAY_OR_AFTER:
                        $target_day = $today->addDay(intval($condition_value_text));
                        $mark = ">=";
                        break;
                    case FilterOption::DAY_TODAY_OR_BEFORE:
                        $target_day = $today;
                        $mark = "<=";
                        break;
                    case FilterOption::DAY_LAST_X_DAY_OR_BEFORE:
                        $target_day = $today->addDay(-1 * intval($condition_value_text));
                        $mark = "<=";
                        break;
                    case FilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                        $target_day = $today->addDay(intval($condition_value_text));
                        $mark = "<=";
                        break;
                }
                $model->{$method_name.'DateMarkExment'}($view_column_target, $target_day, $mark, $isDateTime);
                break;
                


            // for select --------------------------------------------------
            case FilterOption::SELECT_EXISTS:
            case FilterOption::SELECT_NOT_EXISTS:
                // if as multiple search
                if ($isMultiple) {
                    $method_name_suffix = isMatchString($view_filter_condition, FilterOption::SELECT_EXISTS) ? 'InArrayString' : 'NotInArrayString';
                    $model->{$method_name.$method_name_suffix}($view_column_target, $condition_value_text);
                }
                // if default
                else {
                    $mark = isMatchString($view_filter_condition, FilterOption::SELECT_EXISTS) ? '=' : '<>';
                    $model->{$method_name . 'OrIn'}($view_column_target, $mark, $condition_value_text);
                }
                break;
        
            // for user --------------------------------------------------
            case FilterOption::USER_EQ_USER:
            case FilterOption::USER_NE_USER:
                $user_id = \Exment::getUserId();
                if ($user_id) {
                    $mark = isMatchString($view_filter_condition, FilterOption::USER_NE_USER) ? '<>' : '=';
                    $model->{$method_name}($view_column_target, $mark, $user_id);
                } else {
                    $model->{$method_name . 'Raw'}('1 = 0');
                }
                break;
        }

        return $model;
    }
}
