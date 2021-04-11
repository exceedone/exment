<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Exceedone\Exment\Enums\FilterOption;

class WorkflowWorkUser extends ViewFilterBase
{
    public static function getFilterOption()
    {
        return FilterOption::WORKFLOW_EQ_WORK_USER;
    }

    /**
     * For condition value, if value is null or empty array, whether ignore the value.
     *
     * @var boolean
     */
    protected static $isConditionNullIgnore = false;

    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        // not use query for workflow.
    }

    /**
     * compare 2 value
     *
     * @param mixed $value. custom value's array.
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    protected function _compareValue($value, $conditionValue) : bool
    {
        if (is_nullorempty($value)) {
            return false;
        }
        return $value->getWorkflowActions(true, true)->count() > 0;
    }
}
