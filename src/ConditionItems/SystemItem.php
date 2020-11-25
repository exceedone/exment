<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\WorkflowTargetSystem;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;

class SystemItem extends ConditionItemBase implements ConditionItemInterface
{
    use ColumnSystemItemTrait;
    
    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value)
    {
        return false;
    }

    /**
     * get text.
     *
     * @param string $key
     * @param string $value
     * @param bool $showFilter
     * @return string
     */
    public function getText($key, $value, $showFilter = true)
    {
        $enum = WorkflowTargetSystem::getEnum($value);
        return isset($enum) ? exmtrans('common.' . $enum->lowerkey()) : null;
    }
    
    
    /**
     * Check has workflow authority with this item.
     *
     * @param WorkflowAuthorityInterface $workflow_authority
     * @param CustomValue|null $custom_value
     * @param CustomValue $targetUser
     * @return boolean
     */
    public function hasAuthority(WorkflowAuthorityInterface $workflow_authority, ?CustomValue $custom_value, $targetUser)
    {
        return $workflow_authority->related_id == WorkflowTargetSystem::CREATED_USER && $custom_value->created_user_id == $targetUser->id;
    }

    public static function setWorkflowConditionQuery($query, $tableName, $custom_table)
    {
        $query->orWhere(function ($query) use ($tableName) {
            $query->where('authority_related_id', WorkflowTargetSystem::CREATED_USER)
                ->where('authority_related_type', ConditionTypeDetail::SYSTEM()->lowerkey())
                ->where($tableName . '.created_user_id', \Exment::getUserId());
        });
    }
}
