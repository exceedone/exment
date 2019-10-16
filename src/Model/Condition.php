<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Form\Field\ChangeField;
use Illuminate\Database\Eloquent\Collection;

/**
 * Custom value condition. Use form priority, workflow action.
 */
class Condition extends ModelBase
{
    use Traits\ColumnOptionQueryTrait;

    protected $guarded = ['id'];
    protected $appends = ['condition_target', 'condition_text'];
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
            case ViewColumnType::CONDITION:
                $condition_type = ConditionType::getEnum($this->target_column_id);
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
        if ($this->condition_type == ConditionType::COLUMN) {
            $condition_type = $this->custom_column->column_view_name;
        } else {
            $condition_type = ConditionType::getEnum($this->condition_type)->transKey('condition.condition_type_options');
        }
        return $condition_type . ' : ' . $this->getConditionText();
    }

    /**
     * get condition value text.
     */
    public function getConditionText() {
        switch ($this->condition_type) {
            case ViewColumnType::CONDITION:
                if($this->target_column_id == ConditionType::USER){
                    $model = getModelName(SystemTableName::USER)::find($this->condition_value);
                    if ($model instanceof Collection) {
                        return $model->map(function($row) {
                            return $row->getValue('user_name');
                        })->implode(',');
                    } else {
                        return $model->getValue('user_name');
                    }
                }
                elseif($this->target_column_id == ConditionType::ORGANIZATION){
                    $model = getModelName(SystemTableName::ORGANIZATION)::find($this->condition_value);
                    if ($model instanceof Collection) {
                        return $model->map(function($row) {
                            return $row->getValue('organization_name');
                        })->implode(',');
                    } else {
                        return $model->getValue('organization_name');
                    }
                }
                elseif($this->target_column_id == ConditionType::ROLE){
                    $model = RoleGroup::find($this->condition_value);
                    if ($model instanceof Collection) {
                        return $model->map(function($row) {
                            return $row->role_group_view_name;
                        })->implode(',');
                    } else {
                        return $model->role_group_view_name;
                    }
                }
                elseif($this->target_column_id == ConditionType::COLUMN){
                    $column_name = $this->custom_column->column_name;
                    $column_item = $this->custom_column->column_item;
                    return $column_item->setCustomValue(["value.$column_name" => $this->condition_value])->text();
                }
                elseif($this->target_column_id == ConditionType::SYSTEM){
                    //TODO:worlflow
                }
                break;
        }
        return $this->condition_value;
    }
    
    
    /**
     * get edited condition_value_text.
     */
    public function getConditionValueAttribute($condition_value)
    {
        if (is_string($this->condition_value)) {
            $array = json_decode($this->condition_value);
            if (is_array($array)) {
                return array_filter($array, function ($val) {
                    return !is_null($val);
                });
            }
        }
        return $this->condition_value;
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
            $this->condition_value = json_encode($array);
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

            case ConditionType::USER:
                $user = \Exment::user();
                return collect($this->condition_value)->contains($user->id);

            case ConditionType::ORGANIZATION:
                $organizations = \Exment::user()->base_user->belong_organizations;
                foreach ($organizations as $organization) {
                    if (collect($this->condition_value)->filter()->contains($organization->id)) {
                        return true;
                    }
                }
                break;
            case ConditionType::ROLE:
                $role_groups = \Exment::user()->base_user->belong_role_groups();
                foreach ($role_groups as $role_group) {
                    if (collect($this->condition_value)->filter()->contains($role_group->id)) {
                        return true;
                    }
                }
                break;
        }
        return false;
    }
}
