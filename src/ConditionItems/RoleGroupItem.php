<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;

class RoleGroupItem extends ConditionItem
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
        $role_groups = \Exment::user()->base_user->belong_role_groups();
        foreach ($role_groups as $role_group) {
            if (collect($this->condition_value)->filter()->contains($role_group->id)) {
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
        $model = RoleGroup::find($this->condition_value);
        if ($model instanceof Collection) {
            return $model->map(function($row) {
                return $row->role_group_view_name;
            })->implode(',');
        } else {
            return $model->role_group_view_name;
        }
    }
}
