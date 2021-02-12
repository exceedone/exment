<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\WorkflowStatus;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\WorkflowStatus;

abstract class WorkflowStatusBase extends ViewFilterBase
{
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
     * @param mixed $value. Condition's array.
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    protected function _compareValue($value, $conditionValue) : bool
    {
        $workflow_status = array_get($value, 'condition_value');
        // if start, $workflow_status set as null
        if (isMatchString($workflow_status, Define::WORKFLOW_START_KEYNAME)) {
            $workflow_status = null;
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
