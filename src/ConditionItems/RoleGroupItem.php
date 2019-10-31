<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\RoleGroup;

class RoleGroupItem extends ConditionItemBase
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
        $role_groups = \Exment::user()->base_user->belong_role_groups()->map(function ($role_group) {
            return $role_group->id;
        })->toArray();

        return $this->compareValue($condition, $role_groups);
    }
    
    /**
     * get condition value text.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function getConditionText(Condition $condition, CustomValue $custom_value)
    {
        $model = RoleGroup::find($this->condition_value);
        if ($model instanceof Collection) {
            return $model->map(function ($row) {
                return $row->role_group_view_name;
            })->implode(',');
        } else {
            return $model->role_group_view_name;
        }
    }

    /**
     * Get change field
     *
     * @param [type] $target_val
     * @param [type] $key
     * @return void
     */
    public function getChangeField($key)
    {
        $options = RoleGroup::all()->pluck('role_group_view_name', 'id');
        $field = new Field\MultipleSelect($this->elementName, [$this->label]);
        return $field->options($options);
    }
}
