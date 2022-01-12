<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\WorkflowTargetSystem;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;
use Exceedone\Exment\ConditionItems\ConditionItemBase;

class WorkflowAuthority extends ModelBase implements WorkflowAuthorityInterface
{
    use Traits\UseRequestSessionTrait;

    public function getAuthorityTextAttribute()
    {
        $item = ConditionItemBase::getDetailItemByAuthority(null, $this);
        if (is_nullorempty($item)) {
            return null;
        }

        $condition_type = ConditionTypeDetail::getEnum($this->related_type);
        if (!isset($condition_type)) {
            return null;
        }
        $condition_type_label = $condition_type->transKey('condition.condition_type_options');
        
        return $item->getText($this->related_type, $this->related_id, false);
    }

    /**
     * Get workflow authorities from value array
     *
     * @param string|array $values
     * @param WorkflowAction $values
     * @return array
     */
    public static function getAuhoritiesFromValue($values, $action = null)
    {
        $values = jsonToArray($values);

        $items = [];
        foreach ($values as $key => $value) {
            foreach ((array)$value as $v) {
                $condition_type = ConditionTypeDetail::getEnum($key);
                if (!isset($condition_type)) {
                    continue;
                }
        
                $authority = new WorkflowAuthority();
                $authority->related_id = $v;
                $authority->related_type = $key;
                $authority->workflow_action_id = isset($action) ? $action->id : null;
    
                $items[] = $authority;
            }
        }

        return $items;
    }


    /**
     * Get this workflow action's user, organizaions, and labels
     *
     * @return array
     */
    public function getWorkflowAuthorityUserOrgLabels(CustomValue $custom_value, WorkflowAction $next_workflow_action, ?WorkflowValue $workflow_value, bool $getAsLoginUser = false) : array
    {
        $type = ConditionTypeDetail::getEnum($this->related_type);
        switch ($type) {
            case ConditionTypeDetail::USER:
                return [
                    'users' => [$this->related_id],
                ];
            case ConditionTypeDetail::ORGANIZATION:
                return [
                    'organizations' => [$this->related_id],
                ];
            case ConditionTypeDetail::SYSTEM:
                if ($this->related_id == WorkflowTargetSystem::CREATED_USER) {
                    return [
                        'users' => [$custom_value->created_user_id],
                    ];
                }
                break;
            case ConditionTypeDetail::COLUMN:
                $column = CustomColumn::getEloquent($this->related_id);
                $column_values = $custom_value->getValue($column);
                if (is_nullorempty($column_values)) {
                    return [];
                }
                if ($column_values instanceof CustomValue) {
                    $column_values = [$column_values];
                }

                $userIds = [];
                $organizationIds = [];
                foreach ($column_values as $column_value) {
                    if ($column->column_type == ColumnType::USER) {
                        $userIds[] = $column_value->id;
                    } else {
                        $organizationIds[] = $column_value->id;
                    }
                }

                // Filter user and org by target table
                $userIds = $custom_value->filterAccessibleUsers($userIds)->toArray();
                $organizationIds = $custom_value->filterAccessibleOrganizations($organizationIds)->toArray();

                return [
                    'users' => $userIds,
                    'organizations' => $organizationIds,
                ];
                
            case ConditionTypeDetail::LOGIN_USER_COLUMN:
                $column = CustomColumn::getEloquent($this->related_id);
                // get target workflow value. By workflow_action's "get_by_userinfo_base".
                $wv = null;
                switch($next_workflow_action->getOption('get_by_userinfo_base')){
                    // If 'first executed user', get first workflow value.
                    case 'first_executed_user':
                        $wv = WorkflowValue::GetFirstExecutedWorkflowValue($custom_value);
                        $getAsLoginUser = false;
                        break;
                    // else, get setted workflow value
                    default:
                        $wv = $workflow_value;
                        break;
                }
                // if $callByExecute is true, Get by action executed user
                // If $workflow_value is empty, this flow is first. So get as login user
                if(is_nullorempty($wv)){
                    $getAsLoginUser = true;
                }
                if($getAsLoginUser){
                    $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(\Exment::getUserId());
                }
                else{
                    $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel($wv->created_user_id);
                }

                $column_values = $user->getValue($column);
                if (is_nullorempty($column_values)) {
                    return [];
                }
                if ($column_values instanceof CustomValue) {
                    $column_values = [$column_values];
                }

                $userIds = [];
                $organizationIds = [];
                foreach ($column_values as $column_value) {
                    if ($column->column_type == ColumnType::USER) {
                        $userIds[] = $column_value->id;
                    } else {
                        $organizationIds[] = $column_value->id;
                    }
                }

                // Filter user and org by target table
                $userIds = $custom_value->filterAccessibleUsers($userIds)->toArray();
                $organizationIds = $custom_value->filterAccessibleOrganizations($organizationIds)->toArray();

                return [
                    'users' => $userIds,
                    'organizations' => $organizationIds,
                ];
        }

        return [];
    }
}
