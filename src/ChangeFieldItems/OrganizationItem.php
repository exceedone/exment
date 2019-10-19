<?php

namespace Exceedone\Exment\ChangeFieldItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\SystemTableName;

class OrganizationItem extends ChangeFieldItem
{
    public function getFilterOption(){
        return $this->getFilterOptionConditon();
    }
    
    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value){
        $organizations = \Exment::user()->base_user->belong_organizations;
        foreach ($organizations as $organization) {
            if (collect($this->condition_value)->filter()->contains($organization->id)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * get condition value text.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function getConditionText(Condition $condition, CustomValue $custom_value){
        $model = getModelName(SystemTableName::ORGANIZATION)::find($this->condition_value);
        if ($model instanceof Collection) {
            return $model->map(function($row) {
                return $row->getValue('organization_name');
            })->implode(',');
        } else {
            return $model->getValue('organization_name');
        }
    }
}
