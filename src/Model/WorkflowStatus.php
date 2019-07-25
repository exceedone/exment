<?php

namespace Exceedone\Exment\Model;

class WorkflowStatus extends ModelBase
{
    /**
     * get workflow status blocks
     */
    public function workflow_status_blocks()
    {
        return $this->hasMany(WorkflowStatusBlock::class, 'workflow_status_id')->orderBy('order');
    }

    public function deletingChildren()
    {
    }
}
