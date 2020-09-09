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
        $selectOption = [
            'display_table' => $this->custom_table
        ];
        $ajax = CustomTable::getEloquent(SystemTableName::USER)->getOptionAjaxUrl($selectOption);
        $field = new Field\MultipleSelect($this->elementName, [$this->label]);

        if (isset($ajax)) {
            $field->attribute([
                'data-add-select2' => $this->label,
                'data-add-select2-ajax' => $ajax,
            ]);
        }
        return $field->options(function($value) use($selectOption){
            $selectOption['selected_value'] = $value;
            return CustomTable::getEloquent(SystemTableName::USER)->getSelectOptions($selectOption);
        });
    }
    
    /**
     * check if custom_value and user(organization, role) match for conditions.
     *
     * @param CustomValue $custom_value
     * @return boolean
     */
    public function isMatchCondition(Condition $condition, CustomValue $custom_value)
    {
        $user = \Exment::getUserId();
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
        $query->orWhere(function ($query) use ($authorityTableName) {
            $query->where($authorityTableName . '.related_id', \Exment::getUserId())
                ->where($authorityTableName . '.related_type', ConditionTypeDetail::USER()->lowerkey());
        });
    }
}
