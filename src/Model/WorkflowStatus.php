<?php

namespace Exceedone\Exment\Model;

class WorkflowStatus extends ModelBase
{
    use Traits\UseRequestSessionTrait;

    public function deletingChildren()
    {
    }

    protected static function boot()
    {
        parent::boot();
        
        // add default order
        static::addGlobalScope(new OrderScope('order'));
    }

    public static function getWorkflowStatusName($workflow_status = null, $workflow = null){
        if(isset($workflow_status) && $workflow_status != Define::WORKFLOW_START_KEYNAME){
            return WorkflowStatus::getEloquentDefault($workflow_status)->status_name;
        }

        // get workflow
        if(isset($workflow)){
            return $workflow->start_status_name;
        }

        return null;
    }
}
