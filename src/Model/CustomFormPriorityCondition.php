<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\FormPriorityType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Database\Eloquent\Collection;

class CustomFormPriorityCondition extends ModelBase
{
    protected $guarded = ['id'];
    protected $appends = ['form_priority_target', 'condition_text'];
    protected $casts = ['form_filter_condition_value' => 'json'];

    public function custom_form_priority()
    {
        return $this->belongsTo(CustomFormPriority::class, 'custom_form_priority_id');
    }

    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'target_column_id');
    }

    public function getFormPriorityTargetAttribute()
    {
        if ($this->form_priority_type == FormPriorityType::COLUMN) {
            return FormPriorityType::COLUMN . '-'. $this->target_column_id;
        } else {
            return $this->form_priority_type;
        }
    }
    
    /**
     * set form_priority_target.
     */
    public function setFormPriorityTargetAttribute($form_priority_target)
    {
        list($form_priority_type, $target_column_id) = explode('-', $form_priority_target) + [null, null];
        $this->form_priority_type = $form_priority_type;
        $this->target_column_id = $target_column_id;
    }

    /**
     * get priority condition text.
     */
    public function getConditionTextAttribute()
    {
        if ($this->form_priority_type == FormPriorityType::COLUMN) {
            $condition_type = $this->custom_column->column_view_name;
        } else {
            $condition_type = FormPriorityType::getEnum($this->form_priority_type)->transKey('custom_form.form_priority_type_options');
        }
        return $condition_type . ' : ' . $this->getFormFilterCondition();
    }

    /**
     * get form filter condition value text.
     */
    public function getFormFilterCondition() {
        switch ($this->form_priority_type) {
            case FormPriorityType::USER:
                $model = getModelName(SystemTableName::USER)::find($this->form_filter_condition_value);
                if ($model instanceof Collection) {
                    return $model->map(function($row) {
                        return $row->getValue('user_name');
                    })->implode(',');
                } else {
                    return $model->getValue('user_name');
                }
                break;
            case FormPriorityType::ORGANIZATION:
                $model = getModelName(SystemTableName::ORGANIZATION)::find($this->form_filter_condition_value);
                if ($model instanceof Collection) {
                    return $model->map(function($row) {
                        return $row->getValue('organization_name');
                    })->implode(',');
                } else {
                    return $model->getValue('organization_name');
                }
                break;
            case FormPriorityType::ROLE:
                $model = RoleGroup::find($this->form_filter_condition_value);
                if ($model instanceof Collection) {
                    return $model->map(function($row) {
                        return $row->role_group_view_name;
                    })->implode(',');
                } else {
                    return $model->role_group_view_name;
                }
                break;
            case FormPriorityType::COLUMN:
                $column_name = $this->custom_column->column_name;
                $column_item = $this->custom_column->column_item;
                return $column_item->setCustomValue(["value.$column_name" => $this->form_filter_condition_value])->text();
        }
        return $this->form_filter_condition_value;
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     */
    public function isMatchCondition($custom_value)
    {
        switch ($this->form_priority_type) {
            case FormPriorityType::COLUMN:
                $column_value = array_get($custom_value, 'value.' . $this->custom_column->column_name);
                if (is_null($column_value)) {
                    return false;
                }
                if (!is_array($column_value)) {
                    $column_value = [$column_value];
                }
                return collect($column_value)->filter()->contains(function ($value) {
                    if (is_array($this->form_filter_condition_value)) {
                        return collect($this->form_filter_condition_value)->filter()->contains($value);
                    } else {
                        return $value == $this->form_filter_condition_value;
                    }
                });

            case FormPriorityType::USER:
                $user = \Exment::user();
                return collect($this->form_filter_condition_value)->contains($user->id);

            case FormPriorityType::ORGANIZATION:
                $organizations = \Exment::user()->base_user->belong_organizations;
                foreach ($organizations as $organization) {
                    if (collect($this->form_filter_condition_value)->filter()->contains($organization->id)) {
                        return true;
                    }
                }
                break;
            case FormPriorityType::ROLE:
                $role_groups = \Exment::user()->base_user->belong_role_groups();
                foreach ($role_groups as $role_group) {
                    if (collect($this->form_filter_condition_value)->filter()->contains($role_group->id)) {
                        return true;
                    }
                }
                break;
        }
        return false;
    }
}
