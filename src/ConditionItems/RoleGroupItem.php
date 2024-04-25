<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Illuminate\Database\Eloquent\Collection;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;

class RoleGroupItem extends ConditionDetailBase implements ConditionItemInterface
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
        $role_groups = \Exment::user()->base_user->belong_role_groups_all()->map(function ($role_group) {
            return $role_group->id;
        })->toArray();

        return $this->compareValue($condition, $role_groups);
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
        $model = RoleGroup::find($value);
        /** @phpstan-ignore-next-line Instanceof between Exceedone\Exment\Model\RoleGroup|null and Illuminate\Database\Eloquent\Collection will always evaluate to false. */
        if ($model instanceof Collection) {
            $result = $model->map(function ($row) {
                return $row->role_group_view_name;
            })->implode(',');
        } else {
            $result = $model->role_group_view_name;
        }
        return $result . ($showFilter ? FilterOption::getConditionKeyText($key) : '');
    }

    /**
     * Get change field
     *
     * @param string $key
     * @param bool $show_condition_key
     * @return \Encore\Admin\Form\Field
     */
    public function getChangeField($key, $show_condition_key = true)
    {
        $options = RoleGroup::all()->pluck('role_group_view_name', 'id');
        $field = new Field\MultipleSelect($this->elementName, [$this->label]);
        return $field->options($options);
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
        $ids = $targetUser->belong_role_groups->pluck('id')->toArray();
        return in_array($workflow_authority->related_id, $ids);
    }
}
