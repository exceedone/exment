<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\SystemTableName;

class OrganizationItem extends ConditionItemBase
{
    public function getFilterOption()
    {
        return $this->getFilterOptionConditon();
    }
    
    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value)
    {
        $organizations = \Exment::user()->base_user->belong_organizations
            ->map(function ($organization) {
                return $organization->id;
            });

        return $this->compareValue($condition, $organizations);
    }
    
    /**
     * get condition value text.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function getConditionText(Condition $condition, CustomValue $custom_value)
    {
        $model = getModelName(SystemTableName::ORGANIZATION)::find($this->condition_value);
        if ($model instanceof Collection) {
            return $model->map(function ($row) {
                return $row->getValue('organization_name');
            })->implode(',');
        } else {
            return $model->getValue('organization_name');
        }
    }

    /**
     * Check has workflow authority
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function hasAuthority($workflow_authority, $custom_value, $targetUser)
    {
        $ids = $targetUser->belong_organizations->pluck('id')->toArray();
        return in_array($workflow_authority->related_id, $ids);
    }
    
    public static function setConditionQuery($query, $tableName)
    {
        $ids = \Exment::user()->base_user->belong_organizations->pluck('id')->toArray();
        $query->orWhere(function ($query) use ($tableName, $ids) {
            $query->whereIn(SystemTableName::WORKFLOW_AUTHORITY . '.related_id', $ids)
                ->where(SystemTableName::WORKFLOW_AUTHORITY . '.related_type', ConditionTypeDetail::ORGANIZATION()->lowerkey());
        });
    }
}
