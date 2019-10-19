<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;

class UserItem extends ConditionItem
{
    public function getFilterOption(){
        return $this->getFilterOptionConditon();
    }
    
    /**
     * Get change field
     *
     * @param [type] $target_val
     * @param [type] $key
     * @return void
     */
    public function getChangeField($key){
        $options = CustomTable::getEloquent(SystemTableName::USER)->getSelectOptions([
            'display_table' => $this->custom_table
        ]);
        $field = new Field\MultipleSelect($this->elementName, [$this->label]);
        return $field->options($options);
    }
    
    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value){
        $user = \Exment::user();
        return collect($this->condition_value)->contains($user->id);
    }
    
    /**
     * get condition value text.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function getConditionText(Condition $condition, CustomValue $custom_value){
        $model = getModelName(SystemTableName::USER)::find($this->condition_value);
        if ($model instanceof Collection) {
            return $model->map(function($row) {
                return $row->getValue('user_name');
            })->implode(',');
        } else {
            return $model->getValue('user_name');
        }
    }
    
    /**
     * Check has workflow authority
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function hasAuthority($workflow_authority, $custom_value, $targetUser){
        return $workflow_authority->related_id == $targetUser->id;
    }
    
}
