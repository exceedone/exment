<?php

namespace Exceedone\Exment\Model;

class WorkflowValue extends ModelBase
{
    use Traits\AutoSUuidTrait;

    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function workflow_action()
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }

    public function workflow_status()
    {
        return $this->belongsTo(WorkflowStatus::class, 'workflow_status_to_id');
    }

    public function workflow_value_authorities()
    {
        return $this->hasMany(WorkflowValueAuthority::class, 'workflow_value_id');
    }

    public function getWorkflowCacheAttribute()
    {
        return Workflow::getEloquent($this->workflow_id);
    }

    public function getWorkflowStatusCacheAttribute()
    {
        return WorkflowStatus::getEloquent($this->workflow_status_to_id);
    }

    public function getWorkflowActionCacheAttribute()
    {
        return WorkflowAction::getEloquent($this->workflow_action_id);
    }

    public function getWorkflowStatusNameAttribute()
    {
        return WorkflowStatus::getWorkflowStatusName($this->workflow_status_to_id, $this->workflow_id);
    }

    public function getWorkflowEditableAttribute()
    {
        $status = $this->getWorkflowStatusAttribute();

        return isset($status)? ($status->editable_flg == 1): true;
    }
    
    /**
     * Whether already executed workflow
     *
     * @return void
     */
    public static function isAlreadyExecuted($custom_value, $targetUser)
    {
        return static::where('morph_type', $custom_value->custom_table->table_name)
            ->where('morph_id', $custom_value->id)
            ->where('action_executed_flg', true)
            ->where('created_user_id', $targetUser->id)
            ->count() > 0;
    }
}
