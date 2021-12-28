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
    public function getWorkflowAuthorityUserOrgLabels(CustomValue $custom_value, ?WorkflowValue $workflow_value, bool $callByExecute, $getAsDefine = false) : array
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
                if ($getAsDefine) {
                    return [
                        'labels' => [exmtrans('common.' . WorkflowTargetSystem::getEnum($this->related_id)->lowerKey())],
                    ];
                }

                if ($this->related_id == WorkflowTargetSystem::CREATED_USER) {
                    return [
                        'users' => [$custom_value->created_user_id],
                    ];
                }
                break;
            case ConditionTypeDetail::COLUMN:
                $column = CustomColumn::getEloquent($this->related_id);

                if ($getAsDefine) {
                    return [
                        'labels' => [$column->column_view_name ?? null],
                    ];
                }

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
                return [
                    'users' => $userIds,
                    'organizations' => $organizationIds,
                ];
                
            case ConditionTypeDetail::LOGIN_USER_COLUMN:
                $column = CustomColumn::getEloquent($this->related_id);

                if ($getAsDefine) {
                    return [
                        'labels' => [$column->column_view_name ?? null],
                    ];
                }

                // if $callByExecute is true, Get by action executed user
                $getAsLoginUser = false;
                if($callByExecute){
                    $getAsLoginUser = true;
                }elseif(is_nullorempty($workflow_value)){
                    $getAsLoginUser = true;
                }

                if($getAsLoginUser){
                    $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(\Exment::getUserId());
                }
                else{
                    $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel($workflow_value->created_user_id);
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
                return [
                    'users' => $userIds,
                    'organizations' => $organizationIds,
                ];
        }

        return [];
    }
}
