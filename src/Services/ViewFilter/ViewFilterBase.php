<?php
namespace Exceedone\Exment\Services\ViewFilter;

abstract class ViewFilterBase
{
    const classNames = [
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

        Items\Exists\SelectExists::class,
        Items\Exists\SelectNotExists::class,
        Items\Exists\UserEq::class,
        Items\Exists\UserNe::class,
        
        Items\UserEqUser\UserEqUser::class,
        Items\UserEqUser\UserNeUser::class,
    ];


    protected $column_item;
    
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


    /**
     * Set filter
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param mixed $query_value
     * @return void
     */
    public function setFilter($query, $query_value)
    {
        $column = $this->column_item->sqlname();

        $method_name = $this->getQueryWhereName();

        $query_value = $this->column_item->convertFilterValue($query_value);

        $this->_setFilter($query, $method_name, $column, $query_value);
    }


    protected function getQueryWhereName() : string
    {
        $where = $this->or_option ? 'orWhere': 'where';

        return $where;
    }


    /**
     * Create instance
     *
     * @param string $view_filter_condition
     * @return ViewFilterBase|null
     */
    public static function make($view_filter_condition, $column_item, array $options = []) : ?ViewFilterBase
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
     * compare 2 value
     *
     * @param mixed $value
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    public function compareValue($value, $conditionValue) : bool
    {
        if (!is_array($value)) {
            $value = [$value];
        }
        if (!is_array($conditionValue)) {
            $conditionValue = [$conditionValue];
        }

        $value = collect($value)->filter(function($value){
            if(static::$isConditionNullIgnore && is_nullorempty($value)){
                return false;
            }
            return true;
        })->toArray();

        return collect($conditionValue)->contains(function ($conditionValue) use ($value) {
            if(static::$isConditionPassAsArray){
                return $this->_compareValue($value, $conditionValue);
            }
    
            return collect($value)->contains(function($value) use($conditionValue){
                return $this->_compareValue($value, $conditionValue);
            });
        });
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
    abstract protected function _compareValue($value, $conditionValue) : bool;
}
