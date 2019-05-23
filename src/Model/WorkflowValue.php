<?php

namespace Exceedone\Exment\Model;

class WorkflowValue extends ModelBase
{
    use Traits\AutoSUuidTrait;

    public function getWorkflowStatusAttribute()
    {
        return WorkflowStatus::where('id', $this->workflow_status_id)
            ->first();
    }

    public function getWorkflowEditableAttribute()
    {
        $status = $this->getWorkflowStatusAttribute();

        return isset($status)? ($status->editable_flg == 1): true;
    }
}
