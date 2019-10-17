<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Form\Field\ChangeField;
use Illuminate\Database\Eloquent\Collection;
use Encore\Admin\Form;

/**
 * Custom value condition. Use form priority, workflow action.
 */
class Condition extends ModelBase
{
    use Traits\ColumnOptionQueryTrait;

    protected $guarded = ['id'];
    protected $appends = ['condition_target'];
    //protected $casts = ['condition_value' => 'json'];

    // public function custom_form_priority()
    // {
    //     return $this->belongsTo(CustomFormPriority::class, 'custom_form_priority_id');
    // }

    // public function custom_column()
    // {
    //     return $this->belongsTo(CustomColumn::class, 'target_column_id');
    // }

    public function getConditionTargetAttribute()
    {
        return $this->getConditionTarget();
    }
    
    /**
     * set condition_target.
     */
    public function setConditionTargetAttribute($condition_target)
    {
        $params = $this->getViewColumnTargetItems($condition_target, null);

        $this->condition_type = $params[0];
        $this->target_column_id = $params[2];
    }

    /**
     * Get target condition.
     *
     * @return void
     */
    public function getConditionTarget()
    {
        switch ($this->condition_type) {
            case ConditionType::CONDITION:
                $condition_type = ConditionTypeDetail::getEnum($this->target_column_id);
                if(!isset($condition_type)){
                    return null;
                }

                return $condition_type->getKey();
        }

        return $this->target_column_id;
    }
    
    /**
     * get priority condition text.
     */
    public function getConditionTextAttribute()
    {
        if ($this->condition_type == ConditionTypeDetail::COLUMN) {
            $condition_type = $this->custom_column->column_view_name;
        } else {
            //TODO:workflow
            return null;
            //$condition_type = ConditionTypeDetail::getEnum($this->condition_type)->transKey('condition.condition_type_options');
        }
        return $condition_type . ' : ' . $this->getConditionText();
    }

    /**
     * get condition value text.
     */
    public function getConditionText() {
        switch ($this->condition_type) {
            case ConditionType::CONDITION:
                if($this->target_column_id == ConditionTypeDetail::USER){
                    $model = getModelName(SystemTableName::USER)::find($this->condition_value);
                    if ($model instanceof Collection) {
                        return $model->map(function($row) {
                            return $row->getValue('user_name');
                        })->implode(',');
                    } else {
                        return $model->getValue('user_name');
                    }
                }
                elseif($this->target_column_id == ConditionTypeDetail::ORGANIZATION){
                    $model = getModelName(SystemTableName::ORGANIZATION)::find($this->condition_value);
                    if ($model instanceof Collection) {
                        return $model->map(function($row) {
                            return $row->getValue('organization_name');
                        })->implode(',');
                    } else {
                        return $model->getValue('organization_name');
                    }
                }
                elseif($this->target_column_id == ConditionTypeDetail::ROLE){
                    $model = RoleGroup::find($this->condition_value);
                    if ($model instanceof Collection) {
                        return $model->map(function($row) {
                            return $row->role_group_view_name;
                        })->implode(',');
                    } else {
                        return $model->role_group_view_name;
                    }
                }
                elseif($this->target_column_id == ConditionTypeDetail::COLUMN){
                    $column_name = $this->custom_column->column_name;
                    $column_item = $this->custom_column->column_item;
                    return $column_item->setCustomValue(["value.$column_name" => $this->condition_value])->text();
                }
                elseif($this->target_column_id == ConditionTypeDetail::SYSTEM){
                    //TODO:worlflow
                }
                break;
        }
        return $this->condition_value;
    }
    
    
    /**
     * get edited condition_value_text.
     */
    public function getConditionValueAttribute()
    {
        $condition_value = array_get($this->attributes, 'condition_value');
        if(is_null($condition_value)){
            return null;
        }

        if (is_string($condition_value)) {
            $array = json_decode($condition_value);
            if (is_array($array)) {
                return array_filter($array, function ($val) {
                    return !is_null($val);
                });
            }
        }
        return $condition_value;
    }
    
    /**
     * set condition_value_text.
     * * we have to convert int if view_filter_condition_value is array*
     */
    public function setConditionValueAttribute($condition_value)
    {
        if (is_array($condition_value)) {
            $array = array_filter($condition_value, function ($val) {
                return !is_null($val);
            });
            $this->attributes['condition_value'] = json_encode($array);
        }else{
            $this->attributes['condition_value'] = $condition_value;
        }
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     */
    public function isMatchCondition($custom_value)
    {
        switch ($this->condition_type) {
            case ConditionType::COLUMN:
                $column_value = array_get($custom_value, 'value.' . $this->custom_column->column_name);
                if (is_null($column_value)) {
                    return false;
                }
                if (!is_array($column_value)) {
                    $column_value = [$column_value];
                }
                return collect($column_value)->filter()->contains(function ($value) {
                    if (is_array($this->condition_value)) {
                        return collect($this->condition_value)->filter()->contains($value);
                    } else {
                        return $value == $this->condition_value;
                    }
                });
            case ConditionType::CONDITION:
                if($this->target_column_id == ConditionTypeDetail::USER){
                    $user = \Exment::user();
                    return collect($this->condition_value)->contains($user->id);
                }
                elseif($this->target_column_id == ConditionTypeDetail::ORGANIZATION){
                    $organizations = \Exment::user()->base_user->belong_organizations;
                    foreach ($organizations as $organization) {
                        if (collect($this->condition_value)->filter()->contains($organization->id)) {
                            return true;
                        }
                    }
                    return false;
                }
                elseif($this->target_column_id == ConditionTypeDetail::ROLE){
                    $role_groups = \Exment::user()->base_user->belong_role_groups();
                    foreach ($role_groups as $role_group) {
                        if (collect($this->condition_value)->filter()->contains($role_group->id)) {
                            return true;
                        }
                    }
                    return false;
                }
                elseif($this->target_column_id == ConditionTypeDetail::COLUMN){
                    $column_name = $this->custom_column->column_name;
                    $column_item = $this->custom_column->column_item;
                    return $column_item->setCustomValue(["value.$column_name" => $this->condition_value])->text();
                }
                elseif($this->target_column_id == ConditionTypeDetail::SYSTEM){
                    //TODO:worlflow
                }
                break;
        }
        return false;
    }

    
    /**
     * get work conditions.
     * *Convert to "_X" format to array. ex.enabled_0
     *
     * @param [type] $work_conditions
     * @return void
     */
    public static function getWorkConditions($work_conditions){
        $work_conditions = jsonToArray($work_conditions);

        // modify work_condition_filter
        $new_work_conditions = [];
        foreach($work_conditions as $key => $work_condition){
            // preg_match using key(as filter)
            preg_match('/(?<key>.+)_(?<no>[0-9])+\[(?<index>.+)\]\[(?<name>.+)\]/u', $key, $match);

            if (!is_nullorempty($match)) {
                $new_work_conditions[array_get($match, 'no')][array_get($match, 'key')][array_get($match, 'index')][array_get($match, 'name')] = $work_condition;
                continue;
            }
            
            // preg_match using key (as enabled)
            preg_match('/(?<key>.+)_(?<no>[0-9])/u', $key, $match);
            if (!is_nullorempty($match)) {
                $new_work_conditions[array_get($match, 'no')][array_get($match, 'key')] = $work_condition;
                continue;
            }

            // default
            $new_work_conditions[$key] = $work_condition;
        }

        // re-loop and replace work_condition_filter
        foreach($new_work_conditions as &$new_work_condition){
            if(!array_has($new_work_condition, 'workflow_conditions')){
                continue;
            }

            $filters = [];
            foreach($new_work_condition['workflow_conditions'] as $k => &$n){
                // remove "_remove_" array
                if(array_has($n, Form::REMOVE_FLAG_NAME)){
                    if(boolval(array_get($n, Form::REMOVE_FLAG_NAME))){
                        array_forget($new_work_condition, $k);
                        break;
                    }
                    array_forget($n, Form::REMOVE_FLAG_NAME);
                }
                $filters[] = $n;
                array_forget($new_work_condition['workflow_conditions'], $k);
            }

            // replace key name "_new_1" to index
            $new_work_condition['workflow_conditions'] = $filters;
        }

        return $new_work_conditions;
    }

}
