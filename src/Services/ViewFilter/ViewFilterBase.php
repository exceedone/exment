<?php
namespace Exceedone\Exment\Services\ViewFilter;

use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Services\ViewFilter\Items;

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
     * Whether this query sets as or
     *
     * @var boolean
     */
    protected $or_option = false;

    
    /**
     * Whether this query sets as Raw (ex. WhereRaw)
     *
     * @var boolean
     */
    protected $queryAsRaw = false;

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

        $this->_setFilter($query, $method_name, $column, $query_value);
    }


    protected function getQueryWhereName() : string
    {
        $where = $this->or_option ? 'orWhere': 'where';

        $where .= $this->queryAsRaw ? 'Raw': '';

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

        foreach($classNames as $className){
            if(isMatchString($view_filter_condition, $className::getFilterOption())){
                return new $className($column_item, $options);
            }
        }

        return null;
    }
}
