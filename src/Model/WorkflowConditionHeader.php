<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Form;

class WorkflowConditionHeader extends ModelBase
{
    public function workflow_action()
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }
    
    public function workflow_conditions()
    {
        return $this->morphMany(Condition::class, 'morph', 'morph_type', 'morph_id');
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     */
    public function isMatchCondition($custom_value)
    {
        foreach ($this->workflow_conditions as $condition) {
            if (!$condition->isMatchCondition($custom_value)) {
                return false;
            }
        }
        return true;
    }

    protected static function boot() {
        parent::boot();

        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
    
    public function deletingChildren()
    {
        $keys = ['workflow_conditions'];
        $this->load($keys);
        foreach($keys as $key){
            $this->{$key}()->delete();
        }
    }
}
