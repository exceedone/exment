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

    /**
     * Get workflow actions from status rom
     *
     * @param [type] $workflow_status
     * @param [type] $workflow
     * @return void
     */
    public static function getActionsByFrom($workflow_status = null, $workflow = null, $ignoreReject = false){
        if(!isset($workflow_status)){
            $workflow_status = Define::WORKFLOW_START_KEYNAME;
        }

        return WorkflowAction::where('workflow_id', $workflow->id)
            ->where('status_from', $workflow_status)
            ->get()
            ->filter(function($action) use($ignoreReject){
                if(!$ignoreReject){
                    return true;
                }

                return !boolval($action->getOption('reject_action'));
            });
    }
}
