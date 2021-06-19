<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\WorkflowStatus;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Enums\FilterOption;

abstract class WorkflowStatusBase extends ViewFilterBase
{
    /**
     * For condition value, if value is null or empty array, whether ignore the value.
     *
     * @var boolean
     */
    protected static $isConditionNullIgnore = false;

    /**
     * If true, called setFilter function, append column name.
     * If append cast, please set false.
     *
     * @var boolean
     */
    protected static $isAppendDatabaseTable = false;


    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        // if $status is start
        $status = $query_value;
        $condition = static::getFilterOption();
        $or_option = $this->or_option;

        if ($status == Define::WORKFLOW_START_KEYNAME) {
            if ($condition == FilterOption::WORKFLOW_NE_STATUS) {
                $func = $or_option ? 'orWhereNotNull': 'whereNotNull';
            } else {
                $func = $or_option ? 'orWhereNull': 'whereNull';
            }
            $query->{$func}('workflow_status_to_id');
        } else {
            if ($condition == FilterOption::WORKFLOW_NE_STATUS) {
                $func = $or_option ? 'orWhere': 'where';
                $query->{$func}(function ($query) use ($status) {
                    $query->where('workflow_status_to_id', '<>', $status)
                        ->orWhereNull('workflow_status_to_id');
                });
            } else {
                $func = $or_option ? 'orWhere': 'where';
                $query->{$func}('workflow_status_to_id', $status);
            }
        }

        return $query;
    }
    

    /**
     * compare 2 value
     *
     * @param mixed $value. Condition's array.
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    protected function _compareValue($value, $conditionValue) : bool
    {
        $workflow_status = array_get($value, 'workflow_status');
        // if start, $workflow_status set as null
        if (isMatchString($workflow_status, Define::WORKFLOW_START_KEYNAME)) {
            $workflow_status = null;
        } elseif ($workflow_status instanceof WorkflowStatus) {
            $workflow_status = $workflow_status->id;
        }

        // if $conditionValue is WorkflowStatus, convert id
        if ($conditionValue instanceof WorkflowStatus) {
            $conditionValue = $conditionValue->id;
        } elseif (isMatchString($conditionValue, Define::WORKFLOW_START_KEYNAME)) {
            $conditionValue = null;
        }

        return isMatchString($workflow_status, $conditionValue) === $this->isExists();
    }
        
    abstract protected function isExists() : bool;
}
