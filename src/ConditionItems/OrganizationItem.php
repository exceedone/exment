<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;

class OrganizationItem extends ConditionItemBase implements ConditionItemInterface
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
        list($options, $ajax) = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getSelectOptionsAndAjaxUrl($selectOption);
        $field = new Field\MultipleSelect($this->elementName, [$this->label]);

        if (isset($ajax)) {
            $field->attribute([
                'data-add-select2' => $this->label,
                'data-add-select2-ajax' => $ajax,
            ]);
        }
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
        $ids = \Exment::user()->belong_organizations->pluck('id')->toArray();
        return $this->compareValue($condition, $ids);
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
        $model = getModelName(SystemTableName::ORGANIZATION)::find($value);
        if ($model instanceof \Illuminate\Database\Eloquent\Collection) {
            $result = $model->filter()->map(function ($row) {
                return $row->getValue('organization_name');
            })->implode(',');
        } else {
            if (!isset($model)) {
                return null;
            }

            $result = $model->getValue('organization_name');
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
        $ids = $targetUser->belong_organizations->pluck('id')->toArray();
        return in_array($workflow_authority->related_id, $ids);
    }
    
    public static function setConditionQuery($query, $tableName, $custom_table, $authorityTableName = SystemTableName::WORKFLOW_AUTHORITY)
    {
        $ids = \Exment::user()->base_user->belong_organizations->pluck('id')->toArray();
        $query->orWhere(function ($query) use ($ids, $authorityTableName) {
            $query->whereIn($authorityTableName . '.related_id', $ids)
                ->where($authorityTableName . '.related_type', ConditionTypeDetail::ORGANIZATION()->lowerkey());
        });
    }
}
