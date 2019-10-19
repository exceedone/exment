<?php

namespace Exceedone\Exment\Model;

class WorkflowValue extends ModelBase
{
    use Traits\AutoSUuidTrait;

    public function workflow_status()
    {
        return $this->belongsTo(WorkflowStatus::class, 'workflow_status_id');
    }

    public function getWorkflowEditableAttribute()
    {
        $status = $this->getWorkflowStatusAttribute();

        return isset($status)? ($status->editable_flg == 1): true;
    }
    
    public function getWorkflowActionAttribute()
    {
        return WorkflowAction::getEloquentDefault($this->workflow_action_id);
    }
}
