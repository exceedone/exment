<?php

namespace Exceedone\Exment\Model;

class WorkflowValue extends ModelBase
{
    use Traits\AutoSUuidTrait;

    public function workflow_status()
    {
        return $this->belongsTo(WorkflowStatus::class, 'workflow_status_id');
    }

    public function workflow_value_authorities()
    {
        return $this->hasMany(WorkflowValueAuthority::class, 'workflow_value_id');
    }

    public function getWorkflowStatusNameAttribute()
    {
        return WorkflowStatus::getWorkflowStatusName($this->workflow_status_id, $this->workflow_id);
        //return $this->belongsTo(WorkflowStatus::class, 'workflow_status_id');
    }

    public function getWorkflowEditableAttribute()
    {
        $status = $this->getWorkflowStatusAttribute();

        return isset($status)? ($status->editable_flg == 1): true;
    }
    
    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function workflow_action()
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }
}
