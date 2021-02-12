<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomColumn;

class WorkflowItem extends SystemItem implements ConditionItemInterface
{
    public function getFilterOption()
    {
        $target = explode('?', $this->target)[0];
        return array_get(FilterOption::FILTER_OPTIONS(), $target == SystemColumn::WORKFLOW_STATUS ? FilterType::WORKFLOW : FilterType::WORKFLOW_WORK_USER);
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value)
    {
        $enum = SystemColumn::getEnum($condition->target_column_id);
        switch($enum){
            case SystemColumn::WORKFLOW_STATUS:
                //$status = $this->getWorkflowStatus($condition);
                return $this->compareValue($condition, $condition);
            case SystemColumn::WORKFLOW_WORK_USERS:
                // Now only match type is login user. So especially logic.
                return $this->compareValue($condition, $custom_value);
        }

        return false;
    }
    

    /**
     * Get Condition Label
     *
     * @return void
     */
    public function getConditionLabel(Condition $condition)
    {
        $enum = SystemColumn::getEnum($condition->target_column_id);
        return exmtrans("common." . $enum->lowerKey());
    }


    /**
     * get condition value text.
     *
     * @param Condition $condition
     * @return string
     */
    public function getConditionText(Condition $condition)
    {
        $enum = SystemColumn::getEnum($condition->target_column_id);
        switch($enum){
            case SystemColumn::WORKFLOW_STATUS:
                return $this->getWorkflowStatus($condition) ?? $condition->condition_value;
            case SystemColumn::WORKFLOW_WORK_USERS:
                // now only work user
                return exmtrans('custom_view.filter_condition_options.eq-user');
        }
    }


    /**
     * get query key Name for display
     *
     * @return string|null
     */
    public function getQueryKey(Condition $condition) : ?string
    {
        $option = SystemColumn::getOption(['id' => $condition->target_column_id]);
        return $option ? $option['name'] : null;
    }


    protected function getWorkflow() : ?Model\Workflow
    {
        return Model\Workflow::getWorkflowByTable($this->custom_table);
    }

    protected function getWorkflowStatus(Condition $condition) : ?string
    {
        return Model\WorkflowStatus::getWorkflowStatusName($condition->condition_value);
    }
}
