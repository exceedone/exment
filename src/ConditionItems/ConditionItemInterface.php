<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;

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
     * @param [type] $target_val
     * @param [type] $key
     * @return void
     */
    public function getChangeField($key, $show_condition_key = true);
    
    /**
     * Check has workflow authority
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function hasAuthority($workflow_authority, $custom_value, $targetUser);
}
