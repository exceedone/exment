<?php

namespace Exceedone\Exment\Model;

class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;
    
    /**
     * get workflow statuses
     */
    public function workflow_statuses()
    {
        return $this->hasMany(WorkflowStatus::class, 'workflow_id');
    }

    /**
     * get workflow actions
     */
    public function workflow_actions()
    {
        return $this->hasMany(WorkflowAction::class, 'workflow_id');
    }
}
