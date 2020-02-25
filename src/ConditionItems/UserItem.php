<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ConditionTypeDetail;

class UserItem extends ConditionItemBase implements ConditionItemInterface
{
    public function getFilterOption()
    {
        return $this->getFilterOptionConditon();
    }
    
    /**
     * Get change field
     *
     * @param [type] $target_val
     * @param [type] $key
     * @return void
     */
    public function getChangeField($key, $show_condition_key = true)
    {
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
    public function isMatchCondition(Condition $condition, CustomValue $custom_value)
    {
        $user = \Exment::user()->base_user_id;
        return $this->compareValue($condition, $user);
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
        $model = getModelName(SystemTableName::USER)::find($value);
        if ($model instanceof \Illuminate\Database\Eloquent\Collection) {
            $result = $model->filter()->map(function ($row) {
                return $row->getValue('user_name');
            })->implode(',');
        } else {
            if (!isset($model)) {
                return null;
            }

            $result = $model->getValue('user_name');
        }
        return $result . ($showFilter ? FilterOption::getConditionKeyText($key) : '');
    }
    
    /**
     * Check has workflow authority
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function hasAuthority($workflow_authority, $custom_value, $targetUser)
    {
        return $workflow_authority->related_id == $targetUser->id;
    }

    public static function setConditionQuery($query, $tableName, $custom_table, $authorityTableName = SystemTableName::WORKFLOW_AUTHORITY)
    {
        $query->orWhere(function ($query) use ($tableName, $authorityTableName) {
            $query->where($authorityTableName . '.related_id', \Exment::user()->base_user_id)
                ->where($authorityTableName . '.related_type', ConditionTypeDetail::USER()->lowerkey());
        });
    }
}
