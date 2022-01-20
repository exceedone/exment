<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\SystemTableName;

class LoginUserColumnItem extends ColumnItem
{
    /**
     * Set condition query. For data list and use workflow status
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param string $tableName
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setWorkflowConditionQuery($query, $tableName, $custom_table)
    {
        // get workflow
        $workflow = Workflow::getWorkflowByTable($custom_table);
        if(!$workflow){
            $query->whereNotMatch();
            return;
        }

        ///// get workflow actions.
        // Filtering "get by userinfo".
        // 
        $workflow_actions = $workflow->workflow_actions
            ->filter(function($workflow_action){
                if($workflow_action->getOption("work_target_type") != WorkflowWorkTargetType::GET_BY_USERINFO){
                    return false;
                }

                return true;
            });

        $orgids = \Exment::user()->base_user->belong_organizations->pluck('id')->toArray();
        $query->orWhere(function ($query) use ($orgids, $workflow, $workflow_actions) {
            foreach($workflow_actions as $workflow_action){
                foreach($workflow_action->work_targets as $work_target_key => $work_target){
                    if($work_target_key != ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerkey()){
                        continue;
                    }

                    foreach($work_target as $login_user_column){
                        $custom_column = CustomColumn::getEloquent($login_user_column);
                        if(!$custom_column){
                            continue;
                        }
        
                        // get key
                        $queryKey = $workflow->getOption('get_by_userinfo_base') == 'first_executed_user' ? 'first_executed_user.value->' : 'executed_user.value->'; 
        
                        $query->orWhere(function($query) use($orgids, $custom_column, $workflow_action, $queryKey){
                                        
                            $query->where('authority_related_id', $custom_column->id)
                                ->where('authority_related_type', ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerkey())
                                ->where('workflow_action_id', $workflow_action->id);

                            if ($custom_column->column_type == ColumnType::USER) {
                                if ($custom_column->isMultipleEnabled()) {
                                    $query->whereInArrayString("{$queryKey}{$custom_column->column_name}", \Exment::getUserId());
                                } else {
                                    $query->where("{$queryKey}{$custom_column->column_name}", \Exment::getUserId());
                                }
                            } else {
                                $query->whereIn("{$queryKey}{$custom_column->column_name}", $orgids);
                            }
                        });
                    }
                }
            }
        });
    }

    
    /**
     * Check has workflow authority with this item.
     *
     * @param WorkflowAuthorityInterface $workflow_authority
     * @param CustomValue|null $custom_value
     * @param CustomValue $targetUser
     * @return boolean
     */
    public function hasAuthorityOld(WorkflowAuthorityInterface $workflow_authority, ?CustomValue $custom_value, $targetUser)
    {
        $custom_column = CustomColumn::find($workflow_authority->related_id);
        if (!ColumnType::isUserOrganization($custom_column->column_type)) {
            return false;
        }
        $auth_values = array_get($custom_value, 'value.' . $custom_column->column_name);
        if (is_null($auth_values)) {
            return false;
        }
        if (!is_array($auth_values)) {
            $auth_values = [$auth_values];
        }

        switch ($custom_column->column_type) {
            case ColumnType::USER:
                return in_array($targetUser->id, $auth_values);
            case ColumnType::ORGANIZATION:
                $ids = $targetUser->belong_organizations->pluck('id')->toArray();
                return collect($auth_values)->contains(function ($auth_value) use ($ids) {
                    return collect($ids)->contains($auth_value);
                });
        }
        return false;
    }
    

    public function hasAuthority(WorkflowAuthorityInterface $workflow_authority, ?CustomValue $custom_value, $targetUser)
    {
        return \Exceedone\Exment\ConditionItems\LoginUserColumnItem::getTargetUserAndOrg($custom_value, $workflow, $this->related_id, $getAsLoginUser);
        
        $workflow_action = WorkflowAction::find($workflow_authority->workflow_action_id);
        $custom_column = CustomColumn::find($workflow_authority->related_id);
        if (!ColumnType::isUserOrganization($custom_column->column_type)) {
            return false;
        }
        // get target workflow value. By workflow_action's "get_by_userinfo_base".
        $wv = null;
        switch($workflow_action->workflow->getOption('get_by_userinfo_base')){
            // If 'first executed user', get first workflow value.
            case 'first_executed_user':
                $wv = WorkflowValue::getFirstExecutedWorkflowValue($custom_value);
                break;
            // else, get setted workflow value
            default:
                $wv = $custom_value->workflow_value;
                break;
        }
        // If $workflow_value is empty, this flow is first. So get as login user
        $getAsLoginUser = is_nullorempty($wv);

        if($getAsLoginUser){
            $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(\Exment::getUserId());
        }
        else{
            $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel($wv->created_user_id);
        }

        $auth_values = $user->getValue($custom_column->column_name);
        if (is_nullorempty($auth_values)) {
            return [];
        }
        if ($auth_values instanceof CustomValue) {
            $auth_values = [$auth_values];
        }

        switch ($custom_column->column_type) {
            case ColumnType::USER:
                return collect($auth_values)->contains(function($auth_value) use($targetUser) {
                    return $auth_value->id == $targetUser->id;
                });
            case ColumnType::ORGANIZATION:
                $ids = $targetUser->belong_organizations->pluck('id')->toArray();
                return collect($auth_values)->contains(function ($auth_value) use ($ids) {
                    return collect($ids)->contains($auth_value->id);
                });
        }
    }


    /**
     * Get Action target user and orgs
     *
     * @param CustomValue $custom_value
     * @param Workflow $workflow
     * @param [type] $custom_column_id
     * @param boolean $getAsLoginUser
     * @return array
     */
    public static function getTargetUserAndOrg(CustomValue $custom_value, Workflow $workflow, $custom_column_id, bool $getAsLoginUser = false) : array
    {
        $column = CustomColumn::getEloquent($custom_column_id);
        // get target workflow value. By workflow_action's "get_by_userinfo_base".
        $wv = null;
        switch($workflow->getOption('get_by_userinfo_base')){
            // If 'first executed user', get first workflow value.
            case 'first_executed_user':
                $wv = WorkflowValue::getFirstExecutedWorkflowValue($custom_value);
                $getAsLoginUser = false;
                break;
            // else, get setted last workflow value
            default:
                $wv = WorkflowValue::getLastExecutedWorkflowValue($custom_value);
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
        $custom_table = $custom_value->custom_table;
        $userIds = $custom_table->filterAccessibleUsers($userIds)->toArray();
        $organizationIds = $custom_table->filterAccessibleOrganizations($organizationIds)->toArray();

        return [
            'users' => $userIds,
            'organizations' => $organizationIds,
        ];
    }
}
