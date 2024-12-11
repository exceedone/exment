<?php

namespace Exceedone\Exment\Services\ViewFilter;

use Exceedone\Exment\ColumnItems\ItemInterface;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ColumnType;

abstract class ViewFilterBase
{
    public const classNames = [
        Items\Eq::class,
        Items\Ne::class,
        Items\Like::class,
        Items\NotLike::class,

        Items\Null\NullClass::class,
        Items\Null\DayNull::class,
        Items\Null\UserNull::class,

        Items\NotNull\NotNull::class,
        Items\NotNull\DayNotNull::class,
        Items\NotNull\UserNotNull::class,

        Items\Number\NumberGt::class,
        Items\Number\NumberLt::class,
        Items\Number\NumberGte::class,
        Items\Number\NumberLte::class,

        Items\DayOn\DayOn::class,
        Items\DayOn\DayToday::class,
        Items\DayOn\DayTomorrow::class,
        Items\DayOn\DayYesterday::class,

        Items\DayMonth\DayThisMonth::class,
        Items\DayMonth\DayLastMonth::class,
        Items\DayMonth\DayNextMonth::class,

        Items\DayYear\DayThisYear::class,
        Items\DayYear\DayLastYear::class,
        Items\DayYear\DayNextYear::class,

        Items\DayBeforeAfter\DayLastXDayOrAfter::class,
        Items\DayBeforeAfter\DayLastXDayOrBefore::class,
        Items\DayBeforeAfter\DayNextXDayOrAfter::class,
        Items\DayBeforeAfter\DayNextXDayOrBefore::class,
        Items\DayBeforeAfter\DayOnOrAfter::class,
        Items\DayBeforeAfter\DayOnOrBefore::class,
        Items\DayBeforeAfter\DayTodayOrAfter::class,
        Items\DayBeforeAfter\DayTodayOrBefore::class,

        Items\TimeBeforeAfter\TimeOnOrAfter::class,
        Items\TimeBeforeAfter\TimeOnOrBefore::class,

        Items\Exists\SelectExists::class,
        Items\Exists\SelectNotExists::class,
        Items\Exists\UserEq::class,
        Items\Exists\UserNe::class,

        Items\UserEqUser\UserEqUser::class,
        Items\UserEqUser\UserNeUser::class,

        Items\WorkflowStatus\WorkflowStatusEq::class,
        Items\WorkflowStatus\WorkflowStatusNe::class,
        Items\WorkflowWorkUser::class,
    ];


    /**
     * column item.
     *
     * @var ItemInterface
     */
    protected $column_item;

    /**
     * Condition, for form priority, workflow, etc's match.
     *
     * @var Condition
     */
    protected $condition;

    /**
     * For condition value, if value is null or empty array, whether ignore the value.
     *
     * @var boolean
     */
    protected static $isConditionNullIgnore = true;

    /**
     * If true, function "_compareValue" pass as array
     *
     * @var boolean
     */
    protected static $isConditionPassAsArray = false;

    /**
     * If true, called setFilter function, append column name.
     * If append cast, please set false.
     *
     * @var boolean
     */
    protected static $isAppendDatabaseTable = true;

    /**
     * Whether this query sets as or
     *
     * @var boolean
     */
    protected $or_option = false;

    public function __construct($column_item, array $options = [])
    {
        $this->column_item = $column_item;

        $options = array_merge(['or_option' => false], $options);
        $this->or_option = boolval($options['or_option']);
    }


    public function setCondition(Condition $condition)
    {
        $this->condition = $condition;
        return $this;
    }


    /**
     * Set filter
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param mixed $query_value
     * @return void
     */
    public function setFilter($query, $query_value)
    {
        $column = static::$isAppendDatabaseTable ? $this->column_item->getTableColumn() : $this->column_item->sqlname();

        $method_name = $this->getQueryWhereName();

        $query_value = $this->column_item->convertFilterValue($query_value);

        $this->_setFilter($query, $method_name, $column, $query_value);
    }


    protected function getQueryWhereName(): string
    {
        $where = $this->or_option ? 'orWhere' : 'where';

        return $where;
    }


    /**
     * Create instance, for view filter
     *
     * @param string $view_filter_condition
     * @return ViewFilterBase|null
     */
    public static function make($view_filter_condition, $column_item, array $options = []): ?ViewFilterBase
    {
        $classNames = static::classNames;

        foreach ($classNames as $className) {
            if (isMatchString($view_filter_condition, $className::getFilterOption())) {
                return new $className($column_item, $options);
            }
        }

        return null;
    }

    /**
     * Create instance, for condition
     *
     * @param Condition $condition
     * @return ViewFilterBase|null
     */
    public static function makeForCondition(Condition $condition, array $options = []): ?ViewFilterBase
    {
        $classNames = static::classNames;

        foreach ($classNames as $className) {
            if (isMatchString($condition->condition_key, $className::getFilterOption())) {
                $instance = new $className(null, $options);
                $instance->setCondition($condition);
                return $instance;
            }
        }

        return null;
    }


    /**
     * compare 2 value
     *
     * @param mixed $value
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    public function compareValue($value, $conditionValue): bool
    {
        if (!is_list($value)) {
            $value = [$value];
        }
        if (!is_list($conditionValue)) {
            $conditionValue = [$conditionValue];
        }

        $value = collect($value)->filter(function ($value) {
            if (static::$isConditionNullIgnore && is_nullorempty($value)) {
                return false;
            }
            return true;
        });

        return collect($conditionValue)->contains(function ($conditionValue) use ($value) {
            if (static::$isConditionPassAsArray) {
                return $this->_compareValue($value, $conditionValue);
            }

            return collect($value)->contains(function ($value) use ($conditionValue) {
                return $this->_compareValue($value, $conditionValue);
            });
        });
    }


    /**
     * Whether this condition is number.
     * If number, compare "eq" and "ne" is as number.
     *
     * @return boolean
     */
    public function isNumeric(): bool
    {
        /** @phpstan-ignore-next-line Negated boolean expression is always false. */
        if (!$this->condition) {
            return false;
        }

        if (!isMatchString($this->condition->condition_type, ConditionType::COLUMN)) {
            return false;
        }

        $custom_column = CustomColumn::getEloquent($this->condition->target_column_id);
        if (!$custom_column) {
            return false;
        }

        return ColumnType::isCalc($custom_column->column_type);
    }


    /**
     * Set filter to query
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param string $method_name 'where' or 'orWhere'
     * @param string $query_column query target name
     * @param mixed $query_value
     * @return void
     */
    abstract protected function _setFilter($query, $method_name, $query_column, $query_value);



    /**
     * compare 2 value
     *
     * @param mixed $value
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    abstract protected function _compareValue($value, $conditionValue): bool;


    /**
     * Get Filter Option.
     *
     * @return string|int
     */
    abstract public static function getFilterOption();
}
