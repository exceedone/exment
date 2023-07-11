<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomValue;

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
        return $this->compareValue($condition, $custom_value);
    }

    /**
     * Get Condition Label
     *
     * @param Condition $condition
     * @return array|bool|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Translation\Translator|mixed|string|void|null
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
    public function getConditionText(Condition $condition): string
    {
        $enum = SystemColumn::getEnum($condition->target_column_id);
        switch ($enum) {
            case SystemColumn::WORKFLOW_STATUS:
                return $this->getWorkflowStatus($condition) ?? $condition->condition_value;
            case SystemColumn::WORKFLOW_WORK_USERS:
                // now only work user
                return exmtrans('custom_view.filter_condition_options.eq-user');
        }
        return '';
    }


    /**
     * get query key Name for display
     *
     * @return string|null
     */
    public function getQueryKey(Condition $condition): ?string
    {
        $option = SystemColumn::getOption(['id' => $condition->target_column_id]);
        return $option ? $option['name'] : null;
    }


    protected function getWorkflow(): ?Model\Workflow
    {
        return Model\Workflow::getWorkflowByTable($this->custom_table);
    }

    protected function getWorkflowStatus(Condition $condition): ?string
    {
        $this->custom_table = $condition->getCustomTable();
        $workflow = $this->getWorkflow();

        return collect($condition->condition_value)->map(function ($v) use ($workflow) {
            return Model\WorkflowStatus::getWorkflowStatusName($v, $workflow);
        })->implode(",");
        //return Model\WorkflowStatus::getWorkflowStatusName($condition->condition_value);
    }
}
