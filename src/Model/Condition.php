<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionType;
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
    protected $casts = ['condition_value' => 'json'];

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
        $key = static::getOptionKey($this->target_column_id);
        if ($this->condition_type == ConditionType::COLUMN) {
            return ConditionType::COLUMN . '-'. $this->target_column_id;
        } else {
            return $this->condition_type;
        }
    }
    
    /**
     * set condition_target.
     */
    public function setConditionTargetAttribute($condition_target)
    {
        list($condition_type, $target_column_id) = explode('-', $condition_target) + [null, null];
        $this->condition_type = $condition_type;
        $this->target_column_id = $target_column_id;
    }

    /**
     * get priority condition text.
     */
    public function getConditionTextAttribute()
    {
        if ($this->condition_type == ConditionType::COLUMN) {
            $condition_type = $this->custom_column->column_view_name;
        } else {
            $condition_type = ConditionType::getEnum($this->condition_type)->transKey('custom_form.condition_type_options');
        }
        return $condition_type . ' : ' . $this->getCondition();
    }

    /**
     * get condition value text.
     */
    public function getCondition() {
        switch ($this->condition_type) {
            case ConditionType::USER:
                $model = getModelName(SystemTableName::USER)::find($this->condition_value);
                if ($model instanceof Collection) {
                    return $model->map(function($row) {
                        return $row->getValue('user_name');
                    })->implode(',');
                } else {
                    return $model->getValue('user_name');
                }
                break;
            case ConditionType::ORGANIZATION:
                $model = getModelName(SystemTableName::ORGANIZATION)::find($this->condition_value);
                if ($model instanceof Collection) {
                    return $model->map(function($row) {
                        return $row->getValue('organization_name');
                    })->implode(',');
                } else {
                    return $model->getValue('organization_name');
                }
                break;
            case ConditionType::ROLE:
                $model = RoleGroup::find($this->condition_value);
                if ($model instanceof Collection) {
                    return $model->map(function($row) {
                        return $row->role_group_view_name;
                    })->implode(',');
                } else {
                    return $model->role_group_view_name;
                }
                break;
            case ConditionType::COLUMN:
                $column_name = $this->custom_column->column_name;
                $column_item = $this->custom_column->column_item;
                return $column_item->setCustomValue(["value.$column_name" => $this->condition_value])->text();
        }
        return $this->condition_value;
    }

    /**
     * get filter condition
     */
    public static function getFilterCondition($target)
    {
        if (!isset($target)) {
            return [];
        }
        
        if(ConditionType::isValidKey($target)){
            $enum = ConditionType::getEnum(strtolower($target));
            $options = array_get(ConditionType::CONDITION_OPTIONS(), $enum->getValue());
        }else{
            // get column item
            $column_item = CustomViewFilter::getColumnItem($target)
                ->options([
                    //'view_column_target' => true,
                ]);

            ///// get column_type
            $column_type = $column_item->getViewFilterType();

            // if null, return []
            if (!isset($column_type)) {
                return [];
            }
    
            $options = array_get(ViewColumnFilterOption::VIEW_COLUMN_FILTER_OPTIONS(), $column_type);
        }

        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.filter_condition_options.'.array_get($array, 'name'))];
        });
    }
    
    /**
     * get filter condition
     */
    public static function getFilterValue($target, $target_val, $target_name)
    {
        if(is_nullorempty($target) || is_nullorempty($target_val) || is_nullorempty($target_name)){
            return [];
        }

        $columnname = 'condition_value';
        $label = exmtrans('custom_form_priority.'.$columnname);

        $field = new ChangeField($columnname, $label);
        $field->data([
            'condition_target' => $target,
            'condition_target_value' => $target_val,
        ])->rules("changeFieldValue:$label");
        $element_name = str_replace('condition_target', 'condition_value', $target_name);
        $field->setElementName($element_name);

        $view = $field->render();
        return \json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
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
