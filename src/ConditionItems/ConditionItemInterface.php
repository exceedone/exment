<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\WorkflowAuthority;

interface ConditionItemInterface
{
    public function getFilterOption();
    
    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value);
    
    /**
     * Get condition label.
     *
     * @param Condition $condition
     * @return boolean
     */
    public function getConditionLabel(Condition $condition);

    /**
     * get condition value text.
     *
     * @param Condition $condition
     * @return boolean
     */
    public function getConditionText(Condition $condition);

    public function getText($key, $value, $showFilter = true);

    /**
     * Get change field
     *
     * @param string $key
     * @param bool $show_condition_key
     * @return \Encore\Admin\Form\Field
     */
    public function getChangeField($key, $show_condition_key = true);
    

    /**
     * Check has workflow authority with this item.
     *
     * @param WorkflowAuthority $workflow_authority
     * @param CustomValue|null $custom_value
     * @param CustomValue $targetUser
     * @return boolean
     */
    public function hasAuthority(WorkflowAuthority $workflow_authority, ?CustomValue $custom_value, $targetUser);
}
