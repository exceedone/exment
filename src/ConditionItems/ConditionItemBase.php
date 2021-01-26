<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowValueAuthority;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Form\Field\ChangeField;
use Exceedone\Exment\Validator\ChangeFieldRule;
use Carbon\Carbon;

/**
 *
 * @method mixed getFilterOption()
 * @method mixed getChangeField($key, $show_condition_key = true)
 * @method string getText($key, $value, $showFilter = true)
 */
abstract class ConditionItemBase implements ConditionItemInterface
{
    protected $custom_table;
    protected $target;
    
    /**
     * Dynamic field element name
     *
     * @var string
     */
    protected $elementName;

    /**
     * Dynamic field class name
     *
     * @var string
     */
    protected $className;

    /**
     * filter kind (view, workflow, form)
     *
     * @var bool
     */
    protected $filterKind;

    /**
     * Dynamic field label
     *
     * @var string
     */
    protected $label;

    public function __construct(?CustomTable $custom_table, $target)
    {
        $this->custom_table = $custom_table;
        $this->target = $target;
    }

    public function setElement($elementName, $className, $label)
    {
        $this->elementName = $elementName;
        $this->className = $className;
        $this->label = $label;

        return $this;
    }
    
    public function filterKind($filterKind = null)
    {
        if (isset($filterKind)) {
            $this->filterKind = $filterKind;
        }

        return $this;
    }
    

    /**
     * Get condition item
     */
    public static function getItem(?CustomTable $custom_table, string $target, string $target_column_id)
    {
        if (is_nullorempty($target)) {
            return null;
        }
        
        return static::getConditionItem($custom_table, $target, $target_column_id);
    }


    /**
     * Get condition item by request
     */
    public static function getItemByRequest(?CustomTable $custom_table, ?string $target_query)
    {
        if (is_nullorempty($target_query)) {
            return null;
        }

        // separate ? for removing table id
        $target = explode('?', $target_query)[0];

        if(!$custom_table){
            // get model by key
            $column_item = CustomViewFilter::getColumnItem($target_query);
            $custom_table = $column_item->getCustomTable();
        }
        
        // convert enum using target_query
        $enum = ConditionType::getEnumByTargetKey(strtolower($target));
        return static::getConditionItem($custom_table, $enum, $target);
    }


    /**
     * get detail item by authority
     *
     * @param CustomTable|null $custom_table
     * @param WorkflowAuthority|WorkflowValueAuthority $authority
     * @return \Exceedone\Exment\ConditionItems\ConditionItemBase
     */
    public static function getDetailItemByAuthority(?CustomTable $custom_table, $authority)
    {   
        return static::getConditionDetailItem($custom_table, $authority->related_type);
    }

    
    /**
     * Get condition type
     *
     * @param CustomTable|null $custom_table
     * @param string $target Condition Type or key name
     * @param string|null $target_column_id
     * @return self
     */
    protected static function getConditionItem(?CustomTable $custom_table, string $target, ?string $target_column_id)
    {
        $enum = ConditionType::getEnum(strtolower($target));
        switch ($enum) {
            case ConditionType::COLUMN:
                return new ColumnItem($custom_table, $target_column_id);
            case ConditionType::SYSTEM:
                return new SystemItem($custom_table, $target_column_id);
            case ConditionType::PARENT_ID:
                return new ParentIdItem($custom_table, $target_column_id);
            case ConditionType::WORKFLOW:
                return new WorkflowItem($custom_table, $target_column_id);
            case ConditionType::CONDITION:
                return static::getConditionDetailItem($custom_table, $target_column_id);
        }
    }


    /**
     * Get condition detail item
     *
     * @param CustomTable|null $custom_table
     * @param string $target
     * @return ConditionItemBase
     */
    protected static function getConditionDetailItem(?CustomTable $custom_table, string $target)
    {
        $enum = ConditionTypeDetail::getEnum(strtolower($target));
        switch ($enum) {
            case ConditionTypeDetail::USER:
                return new UserItem($custom_table, $target);
            case ConditionTypeDetail::ORGANIZATION:
                return new OrganizationItem($custom_table, $target);
            case ConditionTypeDetail::ROLE:
                return new RoleGroupItem($custom_table, $target);
            case ConditionTypeDetail::SYSTEM:
                return new SystemItem($custom_table, $target);
            case ConditionTypeDetail::COLUMN:
                return new ColumnItem($custom_table, $target);
            case ConditionTypeDetail::FORM:
                return new FormDataItem($custom_table, $target);
        }
    }


    /**
     * get filter condition
     */
    public function getFilterCondition()
    {
        $options = $this->getFilterOption();
        
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.filter_condition_options.'.array_get($array, 'name'))];
        });
    }
    
    /**
     * get Update Type Condition
     */
    public function getOperationUpdateType()
    {
        return collect([Enums\OperationUpdateType::DEFAULT])->map(function ($val) {
            return ['id' => $val, 'text' => exmtrans('custom_operation.operation_update_type_options.'.$val)];
        });
    }
    
    /**
     * get Update Type Condition
     */
    public function getOperationFilterValue($target_key, $target_name, $show_condition_key = true)
    {
        return $this->getFilterValue($target_key, $target_name, $show_condition_key);
    }
    
    /**
     * get filter value
     */
    public function getFilterValueAjax($target_key, $target_name, $show_condition_key = true)
    {
        $field = $this->getFilterValue($target_key, $target_name, $show_condition_key);
        if (is_null($field)) {
            return [];
        }
        
        $view = $field->render();
        return json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
    }

    /**
     * get filter value
     */
    public function getFilterValue($target_key, $target_name, $show_condition_key = true)
    {
        if (is_nullorempty($this->target) || is_nullorempty($target_key) || is_nullorempty($target_name)) {
            return null;
        }

        $field = new ChangeField($this->className, $this->label);
        $field->rules([new ChangeFieldRule($this->custom_table, $this->label, $this->target)]);
        $field->adminField(function () use ($target_key, $show_condition_key) {
            return $this->getChangeField($target_key, $show_condition_key);
        });
        $field->setElementName($this->elementName);

        return $field;
    }

    protected function getFilterOptionConditon()
    {
        return array_get(FilterOption::FILTER_OPTIONS(), FilterType::CONDITION);
    }

    /**
     * Get Condition Label
     *
     * @return void
     */
    public function getConditionLabel(Condition $condition)
    {
        $enum = ConditionTypeDetail::getEnum($condition->target_column_id);
        return $enum->transKey('condition.condition_type_options') ?: null;
    }

    /**
     * compare condition value and saved value
     *
     * @param Condition $condition
     * @param mixed $value
     * @return bool
     */
    protected function compareValue($condition, $value)
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $condition_value = $condition->condition_value;
        if (!is_array($condition_value)) {
            $condition_value = [$condition_value];
        }

        $compareOption = FilterOption::getCompareOptions($condition->condition_key);
        return collect($value)->filter(function ($v) use ($compareOption) {
            switch ($compareOption) {
                case FilterOption::NULL:
                case FilterOption::NOT_NULL:
                    return true;
                default:
                    return isset($v);
            }
        })->contains(function ($v) use ($condition_value, $compareOption) {
            return collect($condition_value)->contains(function ($condition_value) use ($v, $compareOption) {
                switch ($compareOption) {
                    case FilterOption::NULL:
                        return is_nullorempty($v);
                    case FilterOption::NOT_NULL:
                        return !is_nullorempty($v);
                    case FilterOption::EQ:
                        return $v == $condition_value;
                    case FilterOption::NE:
                        return $v != $condition_value;
                    case FilterOption::LIKE:
                        return (strpos($v, $condition_value) !== false);
                    case FilterOption::NOT_LIKE:
                        return (strpos($v, $condition_value) === false);
                    case FilterOption::NUMBER_GT:
                        return $v > $condition_value;
                    case FilterOption::NUMBER_GTE:
                        return $v >= $condition_value;
                    case FilterOption::NUMBER_LT:
                        return $v < $condition_value;
                    case FilterOption::NUMBER_LTE:
                        return $v <= $condition_value;
                    case FilterOption::USER_EQ_USER:
                        return $v == \Exment::user()->base_user->id;
                    case FilterOption::USER_NE_USER:
                        return $v != \Exment::user()->base_user->id;
                    case FilterOption::DAY_ON:
                        $condition_dt = Carbon::parse($condition_value);
                        return Carbon::parse($v)->isSameDay($condition_dt);
                    case FilterOption::DAY_ON_OR_AFTER:
                        $condition_dt = Carbon::parse($condition_value);
                        return Carbon::parse($v)->gte($condition_dt);
                    case FilterOption::DAY_ON_OR_BEFORE:
                        $condition_dt = Carbon::parse($condition_value)->addDays(1);
                        return Carbon::parse($v)->lt($condition_dt);
                    case FilterOption::DAY_TODAY_OR_AFTER:
                    case FilterOption::DAY_TODAY_OR_BEFORE:
                        $today = Carbon::today();
                        switch ($compareOption) {
                            case FilterOption::DAY_TODAY_OR_AFTER:
                                return Carbon::parse($v)->gte($today);
                            case FilterOption::DAY_TODAY_OR_BEFORE:
                                return Carbon::parse($v)->lte($today);
                        }
                        // no break
                    case FilterOption::DAY_TODAY:
                        return Carbon::parse($v)->isToday();
                    case FilterOption::DAY_YESTERDAY:
                        return Carbon::parse($v)->isYesterday();
                    case FilterOption::DAY_TOMORROW:
                        return Carbon::parse($v)->isTomorrow();
                    case FilterOption::DAY_THIS_MONTH:
                        $target_day = Carbon::parse($v);
                        return $target_day->isCurrentYear() && $target_day->isCurrentMonth();
                    case FilterOption::DAY_NEXT_MONTH:
                        $target_day = Carbon::parse($v);
                        return $target_day->isCurrentYear() && $target_day->isNextMonth();
                    case FilterOption::DAY_LAST_MONTH:
                        $target_day = Carbon::parse($v);
                        return $target_day->isCurrentYear() && $target_day->isLastMonth();
                    case FilterOption::DAY_THIS_YEAR:
                        return Carbon::parse($v)->isCurrentYear();
                    case FilterOption::DAY_NEXT_YEAR:
                        return Carbon::parse($v)->isNextYear();
                    case FilterOption::DAY_LAST_YEAR:
                        return Carbon::parse($v)->isLastYear();
                    case FilterOption::DAY_TODAY_OR_AFTER:
                    case FilterOption::DAY_TODAY_OR_BEFORE:
                    case FilterOption::DAY_LAST_X_DAY_OR_AFTER:
                    case FilterOption::DAY_NEXT_X_DAY_OR_AFTER:
                    case FilterOption::DAY_LAST_X_DAY_OR_BEFORE:
                    case FilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                        $today = Carbon::today();
                        // compare target day and calculated day
                        switch ($compareOption) {
                            case FilterOption::DAY_TODAY_OR_AFTER:
                                return Carbon::parse($v)->gte($today);
                            case FilterOption::DAY_TODAY_OR_BEFORE:
                                return Carbon::parse($v)->lte($today);
                            case FilterOption::DAY_LAST_X_DAY_OR_AFTER:
                                $target_day = $today->addDay(-1 * intval($condition_value));
                                return Carbon::parse($v)->gte($target_day);
                            case FilterOption::DAY_NEXT_X_DAY_OR_AFTER:
                                $target_day = $today->addDay(intval($condition_value));
                                return Carbon::parse($v)->gte($target_day);
                            case FilterOption::DAY_LAST_X_DAY_OR_BEFORE:
                                $target_day = $today->addDay(-1 * intval($condition_value));
                                return Carbon::parse($v)->lte($target_day);
                            case FilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                                $target_day = $today->addDay(intval($condition_value));
                                return Carbon::parse($v)->lte($target_day);
                        }
                    }
                return false;
            });
        });
    }
    
    /**
     * get condition value text.
     *
     * @param Condition $condition
     * @return string
     */
    public function getConditionText(Condition $condition)
    {
        return $this->getText($condition->condition_key, $condition->condition_value);
    }


    /**
     * get query key Name for display
     *
     * @return string|null
     */
    public function getQueryKey(Condition $condition) : ?string
    {
        return $condition->target_column_id;
    }


    /**
     * Set query sort for custom value's sort
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param Model\CustomViewSort $custom_view_sort
     * @return void
     */
    public function setQuerySort($query, Model\CustomViewSort $custom_view_sort)
    {
    }


    /**
     * get select column display text
     *
     * @return string|null
     */
    public function getSelectColumnText(Model\CustomViewColumn $custom_view_column, Model\CustomTable $custom_table) : ?string
    {
        return null;
    }


    /**
     * Whether this column is number
     *
     * @return bool
     */
    public function isSelectColumnNumber(Model\CustomViewColumn $custom_view_column) : bool
    {
        return false;
    }


    /**
     * get Column Key Name for getting value
     *
     * @param string $column_type_target
     * @param Model\CustomColumn $custom_column
     * @return string|null
     */
    public function getColumnValueKey($column_type_target, $custom_column) : ?string
    {
        return null;
    }
    


    /**
     * get column and table id
     *
     * @return array offset 0 : column id, 1 : table id
     */
    public function getColumnAndTableId($column_name, $custom_table) : array
    {
        return [null, null];
    }

}
