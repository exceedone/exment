<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;

class ColumnSystemItem extends ConditionItem
{
    use ColumnSystemItemTrait;
    
    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value){
        return false;
    }

    /**
     * get condition value text.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function getConditionText(Condition $condition, CustomValue $custom_value){
        return null;
    }
    
    /**
     * Check has workflow authority
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function hasAuthority($workflow_authority, $custom_value, $targetUser){
        return $workflow_authority->related_id == WorkflowTargetSystem::CREATED_USER && $custom_value->created_user_id == $targetUser->id;
    }
}
