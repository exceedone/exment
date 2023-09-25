<?php

namespace Exceedone\Exment\ConditionItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;

class OrganizationItem extends ConditionDetailBase implements ConditionItemInterface
{
    use UserOrganizationItemTrait;

    public function getFilterOption()
    {
        return $this->getFilterOptionConditon();
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
        return $this->getChangeFieldUserOrg(CustomTable::getEloquent(SystemTableName::ORGANIZATION), $key, $show_condition_key);
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
     * @return string|null
     */
    public function getText($key, $value, $showFilter = true)
    {
        $model = getModelName(SystemTableName::ORGANIZATION)::find($value);
        if ($model instanceof \Illuminate\Database\Eloquent\Collection) {
            $result = $model->filter()->map(function ($row) {
                /** @var CustomValue $row */
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
     * Check has workflow authority with this item.
     *
     * @param WorkflowAuthorityInterface $workflow_authority
     * @param CustomValue|null $custom_value
     * @param CustomValue $targetUser
     * @return boolean
     */
    public function hasAuthority(WorkflowAuthorityInterface $workflow_authority, ?CustomValue $custom_value, $targetUser)
    {
        $ids = $targetUser->belong_organizations->pluck('id')->toArray();
        return in_array($workflow_authority->related_id, $ids);
    }

    public static function setWorkflowConditionQuery($query, $tableName, $custom_table)
    {
        $ids = \Exment::user()->base_user->belong_organizations->pluck('id')->toArray();
        $query->orWhere(function ($query) use ($ids) {
            $query->whereIn('authority_related_id', $ids)
                ->where('authority_related_type', ConditionTypeDetail::ORGANIZATION()->lowerkey());
        });
    }
}
